local pool_key = KEYS[1]
local history_key = KEYS[2]
local now_dow = tonumber(ARGV[1])
local now_min = tonumber(ARGV[2])
local utc_ts = tonumber(ARGV[3])
local daily_ttl = tonumber(ARGV[4])
local alpha = tonumber(ARGV[5])
local key_prefix = ARGV[6] or ''
local dry_run = tonumber(ARGV[7]) or 0
local debug_label = ARGV[8] or ''
local history_max = tonumber(ARGV[9]) or 500

local UNLIMITED = 1000000000

local function order_data_key(order_id)
    return key_prefix .. 'order:' .. order_id .. ':data'
end

local function resolve_local_day(tz_offset)
    return math.floor((utc_ts + (tonumber(tz_offset) or 0)) / 86400)
end

local function order_sold_key(order_id, tz_offset)
    local day = tostring(resolve_local_day(tz_offset))
    if dry_run == 1 then
        return key_prefix .. 'order:' .. order_id .. ':sold_dry:' .. day
    end
    return key_prefix .. 'order:' .. order_id .. ':sold:' .. day
end

local function is_available(availability_utc)
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

local function get_sold(order_id, tz_offset)
    return tonumber(redis.call('GET', order_sold_key(order_id, tz_offset)) or '0')
end

local function inc_sold(order_id, tz_offset)
    local k = order_sold_key(order_id, tz_offset)
    local c = redis.call('INCR', k)
    if c == 1 then
        redis.call('EXPIRE', k, daily_ttl)
    end
    return c
end

local function append_match_history(candidates, sumW, totalSold, best_oid)
    if history_key == nil or history_key == false or history_key == '' then
        return
    end

    local N = totalSold + 1
    local lines = {}
    for i = 1, #candidates do
        local c = candidates[i]
        local fair = N * (c.w / sumW)
        local def = fair - c.sold
        local mark = c.oid == best_oid and 'WIN ' or '    '
        table.insert(lines, string.format(
            '%s%s | kind=%-4s | rate=%4s | sold=%3d | w=%8.1f | fair=%6.2f | def=%7.2f',
            mark,
            c.oid,
            c.kind,
            tostring(c.rate),
            c.sold,
            c.w,
            fair,
            def
        ))
    end

    local label = debug_label ~= '' and debug_label or '-'
    local header = string.format(
        '=== lead=%s utc=%d N=%d totalSold=%d winner=%s ===',
        label,
        utc_ts,
        N,
        totalSold,
        best_oid
    )
    local block = header .. '\n' .. table.concat(lines, '\n')

    redis.call('RPUSH', history_key, block)
    redis.call('LTRIM', history_key, -history_max, -1)
    redis.call('EXPIRE', history_key, 86400)
end

local function weight_pow(rate)
    if rate == nil or rate == false then
        return 0.0
    end
    local r = tonumber(rate)
    if r == nil or r <= 0 then
        return 0.0
    end
    if alpha == nil or alpha <= 0 then
        return r
    end
    return r ^ alpha
end

local function effective_capacity(capacity_str, sold)
    if capacity_str == nil or capacity_str == false or capacity_str == '' then
        return UNLIMITED
    end
    local limit = tonumber(capacity_str)
    if limit == nil or limit <= 0 then
        return 0
    end
    return limit - sold
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
local totalSold = 0

for i = 1, #order_ids do
    local oid = order_ids[i]
    local data_key = order_data_key(oid)
    local d = redis.call('HMGET', data_key, 'source', 'rate', 'availability_utc', 'capacity', 'partner_id', 'daily_tz_offset')

    local source = d[1]
    if source == false then
        source = 'lm'
    end

    local rate = d[2]
    local availability_utc = d[3]
    local capacity_str = d[4]
    local partner_id = d[5]
    local daily_tz_offset = d[6]

    if rate ~= false and partner_id ~= false and is_available(availability_utc) then
        local sold = get_sold(oid, daily_tz_offset)
        local cap = effective_capacity(capacity_str, sold)

        if cap > 0 then
            local w = cap * weight_pow(rate)
            if w > 0 then
                totalSold = totalSold + sold
                sumW = sumW + w
                table.insert(candidates, {
                    kind = source,
                    oid = oid,
                    partner_id = partner_id,
                    rate = tonumber(rate) or 0,
                    sold = sold,
                    w = w,
                    daily_tz_offset = daily_tz_offset,
                })
            end
        end
    end
end

if #candidates == 0 or sumW <= 0 then
    return nil
end

local best = nil
local bestDef = -1e18
local N = totalSold + 1

for i = 1, #candidates do
    local c = candidates[i]
    local fair = N * (c.w / sumW)
    local def = fair - c.sold
    if def > bestDef then
        bestDef = def
        best = c
    end
end

if best == nil then
    return nil
end

append_match_history(candidates, sumW, totalSold, best.oid)

inc_sold(best.oid, best.daily_tz_offset)

return {best.kind, best.kind == 'irev' and best.partner_id or best.oid, best.partner_id, tostring(best.rate)}
