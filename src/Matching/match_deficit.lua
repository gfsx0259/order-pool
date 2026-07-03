local pool_key = KEYS[1]
local now_dow = tonumber(ARGV[1])
local now_min = tonumber(ARGV[2])
local utc_ts = tonumber(ARGV[3])
local daily_ttl = tonumber(ARGV[4])
local alpha = tonumber(ARGV[5])
local key_prefix = ARGV[6] or ''
local dry_run = tonumber(ARGV[7]) or 0
local preset_id = string.match(pool_key, 'preset:(%d+):orders_pool') or ''
local history_key = key_prefix .. 'preset:' .. preset_id .. ':history'

local UNLIMITED = 1000000000
local HISTORY_MAX = 500

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
    local key = order_sold_key(order_id, tz_offset)
    local count = redis.call('INCR', key)
    if count == 1 then
        redis.call('EXPIRE', key, daily_ttl)
    end
    return count
end

local function history(candidates, totalSold, winnerOrderId)
    local N = totalSold + 1
    local body

    if winnerOrderId == nil then
        body = string.format('N=%d | STOCK;', N)
    else
        local parts = {}
        for i = 1, #candidates do
            local candidate = candidates[i]
            local shortId
            if candidate.kind == 'irev' then
                local tail = string.match(candidate.orderId, '([^%-]+)$') or candidate.orderId
                shortId = #tail >= 5 and string.sub(tail, -5) or tail
            else
                shortId = tostring(candidate.orderId)
            end
            local capLabel = candidate.cap >= 999999999 and 'unlim' or tostring(math.floor(candidate.cap))
            local kindLabel = candidate.kind == 'irev' and 'IREV' or 'LM'
            local status = candidate.orderId == winnerOrderId and 'WINNER' or 'FAIL'
            local prefix = candidate.orderId == winnerOrderId and '*' or ''
            table.insert(parts, string.format(
                '%s%s %s %d$ %s %s',
                prefix,
                shortId,
                kindLabel,
                math.floor(candidate.rate),
                capLabel,
                status
            ))
        end
        body = string.format('N=%d | %s', N, table.concat(parts, '; ') .. ';')
    end

    local line = string.format('preset=%s | %s', preset_id, body)
    redis.call('RPUSH', history_key, line)
    redis.call('LTRIM', history_key, -HISTORY_MAX, -1)
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

local function try_candidate(orderId)
    local d = redis.call('HMGET', order_data_key(orderId), 'source', 'rate', 'availability_utc', 'capacity', 'partner_id', 'daily_tz_offset')

    local kind = d[1]
    local rate = d[2]
    local availability_utc = d[3]
    local capacity_str = d[4]
    local partner_id = d[5]
    local daily_tz_offset = d[6]

    if kind == false or rate == false or partner_id == false or not is_available(availability_utc) then
        return nil
    end

    local sold = get_sold(orderId, daily_tz_offset)
    local cap = effective_capacity(capacity_str, sold)
    if cap <= 0 then
        return nil
    end

    local weight = cap * weight_pow(rate)
    if weight <= 0 then
        return nil
    end

    return {
        kind = kind,
        orderId = orderId,
        partner_id = partner_id,
        rate = tonumber(rate) or 0,
        daily_tz_offset = daily_tz_offset,
        sold = sold,
        cap = cap,
        weight = weight,
    }
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
local sumWeight = 0.0
local totalSold = 0

for i = 1, #order_ids do
    local candidate = try_candidate(order_ids[i])
    if candidate ~= nil then
        totalSold = totalSold + candidate.sold
        sumWeight = sumWeight + candidate.weight
        table.insert(candidates, candidate)
    end
end

if #candidates == 0 or sumWeight <= 0 then
    history(candidates, totalSold, nil)
    return nil
end

local best = nil
local bestDeficit = -1e18
local N = totalSold + 1

for i = 1, #candidates do
    local candidate = candidates[i]
    local fair = N * (candidate.weight / sumWeight)
    local deficit = fair - candidate.sold
    if deficit > bestDeficit then
        bestDeficit = deficit
        best = candidate
    end
end

if best == nil then
    return nil
end

history(candidates, totalSold, best.orderId)
inc_sold(best.orderId, best.daily_tz_offset)

return {best.kind, best.kind == 'irev' and best.partner_id or best.orderId, best.partner_id, tostring(best.rate)}
