<?php
/** Pure, integer-only licence calculations. No wallet writes. */
final class ThreePM_Licence_Domain {
    const TEMPLATE = 'za-draft-2026-11-03-v2';
    public static function cents($value) {
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,7})(\.[0-9]{1,2})?$/D', $value)) throw new InvalidArgumentException('Use a USD amount with up to two decimal places.');
        $p = explode('.', $value);
        $n = (int)$p[0] * 100 + (int)str_pad($p[1] ?? '', 2, '0');
        if ($n < 1) throw new InvalidArgumentException('Payment must be greater than zero.');
        return $n;
    }
    public static function allocation($cents, $split = null) {
        // USD micro-units preserve exact percentages of a cent. 1 coin = 50,000 micro-USD.
        // Coin amounts use millionths; every USD cent maps exactly at the fixed peg.
        if (!is_int($cents) || $cents < 1 || $cents > 9999999999) throw new InvalidArgumentException('Invalid base amount.');
        $split = $split ?? ['spend_percent'=>75,'hodl_percent'=>5,'platform_percent'=>20];
        $spend=$split['spend_percent']; $hodl=$split['hodl_percent']; $fee=$split['platform_percent'];
        if (!is_int($spend) || !is_int($hodl) || !is_int($fee) || min($spend,$hodl,$fee)<0 || $spend+$hodl+$fee!==100) throw new InvalidArgumentException('Invalid allocation.');
        return ['base_usd_micros'=>$cents*10000, 'spend_usd_micros'=>$cents*$spend*100,
            'hodl_usd_micros'=>$cents*$hodl*100, 'fee_usd_micros'=>$cents*$fee*100,
            'spend_coin_micros'=>$cents*$spend*2000, 'hodl_coin_micros'=>$cents*$hodl*2000];
    }
    public static function date($s) {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s, new DateTimeZone('UTC'));
        if (!$d || $d->format('Y-m-d') !== $s) throw new InvalidArgumentException('Use a valid calendar date.');
        return $d;
    }
    public static function schedule($rows, $start, $end, $perpetuity, $mode) {
        self::date($start); if (!$perpetuity) { self::date($end); if ($end < $start) throw new InvalidArgumentException('End date precedes start.'); }
        if (!is_array($rows) || count($rows)<1 || count($rows)>120 || ($mode==='upfront' && count($rows)!==1)) throw new InvalidArgumentException('Use one upfront payment or 1–120 recurring payments.');
        $out=[]; $previous=null;
        foreach ($rows as $r) {
            $due=self::date($r['due'] ?? '')->format('Y-m-d');
            $from=self::date($r['from'] ?? '')->format('Y-m-d');
            $to=($perpetuity && $mode==='upfront') ? null : self::date($r['to'] ?? '')->format('Y-m-d');
            if ($from<$start || ($to!==null && ($to<$from || (!$perpetuity && $to>$end))) || ($previous!==null && $from<=$previous)) throw new InvalidArgumentException('Coverage must be ordered, non-overlapping and within the licence dates.');
            if ($due>$from) throw new InvalidArgumentException('Payment must be due on or before its coverage starts.');
            $out[]=['amount_cents'=>self::cents($r['amount'] ?? ''),'due'=>$due,'from'=>$from,'to'=>$to]; $previous=$to;
        }
        if ($out[0]['from']!==$start || (!$perpetuity && end($out)['to']!==$end)) throw new InvalidArgumentException('Payment coverage must reach the overall licence start and end.');
        for($i=1;$i<count($out);$i++) if(self::date($out[$i-1]['to'])->modify('+1 day')->format('Y-m-d')!==$out[$i]['from']) throw new InvalidArgumentException('Coverage periods must be contiguous.');
        if ($perpetuity && $mode!=='upfront') throw new InvalidArgumentException('Perpetual recurring coverage requires an approved renewal policy. Use upfront or a finite term.');
        return $out;
    }
    public static function hash($terms) { return hash('sha256', json_encode($terms, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); }
    public static function overdue($due, $now) {
        // Due dates end at 23:59:59 UTC. Seven full calendar days follow.
        $deadline=self::date($due)->modify('+8 days');
        return $now >= $deadline ? 'cancellation_pending_legal_policy' : ($now>=self::date($due)->modify('+1 day') ? 'grace_period' : 'scheduled');
    }
}
