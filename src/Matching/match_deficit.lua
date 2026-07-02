local pool_key = KEYS[1]
local now_dow = tonumber(ARGV[1])
local now_min = tonumber(ARGV[2])
local utc_ts = tonumber(ARGV[3])
local daily_ttl = tonumber(ARGV[4])
local local_day = tonumber(ARGV[5])
local alpha = tonumber(ARGV[6])

local function is_available(availability_utc)
    -- Empty means 24/7 (LM semantics)
    if availability_utc == nil or availability_utc == false or availability_utc == '' then
        return true
    end

    for segment in string.gmatch(availability_utc, '([^,]+)') do
        local dow, start_min, end_min = string.match(segment, '^(%d+):(%d+)-(%d+)$')
        if dow ~= nil and tonumber(dow) == now_dow then
            start_min = tonumber(start_min)
            end_min = tonumber(end_min)
            if now_min >= start_min and now_min < end_min then
                return true
            end
        end
    end

    return false
end

local function get_delivered(order_id)
    local k = 'order:' .. order_id .. ':delivered:' .. tostring(local_day)
    return tonumber(redis.call('GET', k) or '0')
end

local function inc_delivered(order_id)
    local k = 'order:' .. order_id .. ':delivered:' .. tostring(local_day)
    local c = redis.call('INCR', k)
    if c == 1 then
        redis.call('EXPIRE', k, daily_ttl)
    end
    return c
end

local function weight_pow(rate)
    if rate == nil then return 0.0 end
    local r = tonumber(rate)
    if r == nil or r <= 0 then return 0.0 end
    if alpha == nil or alpha <= 0 then
        return r
    end
    return r ^ alpha
end

local exists = redis.call('EXISTS', pool_key)
if exists == 0 then
    return "POOL_NOT_FOUND"
end

local order_ids = redis.call('SMEMBERS', pool_key)
if order_ids == nil or #order_ids == 0 then
    return nil
end

local candidates = {}
local sumW = 0.0
local totalDelivered = 0

for i = 1, #order_ids do
    local oid = order_ids[i]
    local data_key = 'order:' .. oid .. ':data'
    local source = redis.call('HGET', data_key, 'source')

    if source == 'irev' then
        local partner_uuid = redis.call('HGET', data_key, 'partner_uuid')
        local rate = redis.call('HGET', data_key, 'rate')
        local availability_utc = redis.call('HGET', data_key, 'availability_utc')
        if partner_uuid ~= false and is_available(availability_utc) then
            local rem_key = 'irev:' .. partner_uuid .. ':remaining'
            local assigned_key = 'irev:' .. partner_uuid .. ':lm_assigned:' .. tostring(local_day)
            local remaining = tonumber(redis.call('GET', rem_key) or '0')
            local assigned = tonumber(redis.call('GET', assigned_key) or '0')
            local cap = remaining - assigned
            if cap > 0 then
                local delivered = get_delivered(oid)
                local w = cap * weight_pow(rate)
                if w > 0 then
                    sumW = sumW + w
                    totalDelivered = totalDelivered + delivered
                    table.insert(candidates, {kind='irev', oid=oid, uuid=partner_uuid, rate=tonumber(rate) or 0, delivered=delivered, w=w})
                end
            end
        end
    else
        -- LM order (backward compatible with existing schema)
        local order_data = redis.call('HMGET', data_key, 'partner_id', 'final_price', 'availability_utc', 'daily_limit', 'daily_tz_offset')
        local partner_id = order_data[1]
        local final_price = order_data[2]
        local availability_utc = order_data[3]
        local daily_limit = order_data[4]
        local daily_tz_offset = order_data[5]

        if partner_id ~= false and is_available(availability_utc) then
            local delivered = get_delivered(oid)
            local cap = 1e9
            local limit = tonumber(daily_limit)
            if limit ~= nil and limit > 0 then
                cap = limit - delivered
            end
            if cap > 0 then
                local w = cap * weight_pow(final_price)
                if w > 0 then
                    sumW = sumW + w
                    totalDelivered = totalDelivered + delivered
                    table.insert(candidates, {kind='lm', oid=oid, partner_id=partner_id, price=tonumber(final_price) or 0, delivered=delivered, w=w})
                end
            end
        end
    end
end

if #candidates == 0 or sumW <= 0 then
    return nil
end

local best = nil
local bestDef = -1e18
local N = totalDelivered + 1

for i = 1, #candidates do
    local c = candidates[i]
    local fair = N * (c.w / sumW)
    local def = fair - c.delivered
    if def > bestDef then
        bestDef = def
        best = c
    end
end

if best == nil then
    return nil
end

if best.kind == 'lm' then
    inc_delivered(best.oid)
    return {'lm', best.oid, best.partner_id, tostring(best.price)}
end

-- irev
inc_delivered(best.oid)
local assigned_key = 'irev:' .. best.uuid .. ':lm_assigned:' .. tostring(local_day)
local a = redis.call('INCR', assigned_key)
if a == 1 then
    redis.call('EXPIRE', assigned_key, daily_ttl)
end
return {'irev', best.uuid, tostring(best.rate)}

