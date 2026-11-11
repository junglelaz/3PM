<?php
/** Track edition validation. No payments or minting are performed by this module. */
defined('ABSPATH') || exit;
final class ThreePM_Track_Editions {
    const META = '_threepm_track_editions';
    public static function validate($input, $previous = []) {
        if (!is_array($input) || count($input) > 50) throw new InvalidArgumentException('Use at most 50 tracks.');
        $out = [];
        foreach ($input as $track) {
            if (!is_array($track)) throw new InvalidArgumentException('Invalid track.');
            $id = (string)($track['id'] ?? '');
            if (!preg_match('/^[a-f0-9-]{36}$/D', $id) || isset($out[$id])) throw new InvalidArgumentException('Invalid or duplicate track ID.');
            $quantity = (string)($track['quantity'] ?? '');
            if (!preg_match('/^[1-9][0-9]{0,5}$/D', $quantity)) throw new InvalidArgumentException('Edition quantity must be 1–999999.');
            $title = sanitize_text_field($track['title'] ?? '');
            if ($title === '') throw new InvalidArgumentException('Track title is required.');
            $old = $previous[$id] ?? [];
            if (!empty($old['locked']) && ((int)$quantity !== (int)$old['quantity'] || $title !== $old['title'])) throw new InvalidArgumentException('An edition cannot be changed after sales begin.');
            $out[$id] = ['id'=>$id, 'title'=>$title, 'quantity'=>(int)$quantity, 'minted'=>(int)($old['minted']??0), 'locked'=>!empty($old['locked']), 'minting'=>'on_purchase'];
        }
        foreach ($previous as $id=>$old) if (!empty($old['locked']) && !isset($out[$id])) throw new InvalidArgumentException('Sold editions cannot be removed.');
        return $out;
    }
}
