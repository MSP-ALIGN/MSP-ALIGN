<?php
/**
 * One connector setting as a form field (integration pages and the setup wizard).
 * @var array $f field definition (Connector::fields); array $values saved values (secrets: whether one is saved)
 */
echo (function (array $f) use ($values): string {
    $n = $f['name'];
    $v = $values[$n] ?? null;
    $help = !empty($f['help']) ? '<small class="form-text text-muted">' . e($f['help']) . '</small>' : '';
    switch ($f['type']) {
        case 'secret':
            $h = '<input type="password" id="f-' . e($n) . '" name="' . e($n) . '" class="form-control" autocomplete="new-password" placeholder="' . ($v ? '•••••••• saved (leave blank to keep)' : 'Not set') . '">';
            if ($v) {
                $h .= '<div class="form-check mt-1"><input type="checkbox" class="form-check-input" id="clear_' . e($n) . '" name="clear_' . e($n) . '" value="1"><label class="form-check-label small fw-normal" for="clear_' . e($n) . '">Remove saved value</label></div>';
            }
            return '<div class="mb-3"><label for="f-' . e($n) . '">' . e($f['label']) . '</label>' . $h . $help . '</div>';
        case 'select':
            $o = '';
            if ($v !== null && $v !== '' && !isset($f['options'][$v])) {
                $o .= '<option value="' . e($v) . '" selected>' . e($v) . ' (current)</option>';
            }
            foreach ($f['options'] as $k => $l) {
                $o .= '<option value="' . e($k) . '"' . ((string) $v === (string) $k ? ' selected' : '') . '>' . e($l) . '</option>';
            }
            return '<div class="mb-3"><label for="f-' . e($n) . '">' . e($f['label']) . '</label><select name="' . e($n) . '" id="f-' . e($n) . '" class="form-select">' . $o . '</select>' . $help . '</div>';
        case 'switch':
            return '<div class="mb-3"><input type="hidden" name="' . e($n) . '_present" value="1"><div class="form-check form-switch"><input type="checkbox" class="form-check-input" id="f-' . e($n) . '" name="' . e($n) . '" value="1"' . ((string) $v === '1' ? ' checked' : '') . '><label class="form-check-label fw-normal" for="f-' . e($n) . '">' . e($f['label']) . '</label></div>' . $help . '</div>';
        case 'checkboxes':
            $picked = array_filter(explode(',', (string) $v));
            $o = '';
            foreach ($f['options'] as $k => $l) {
                $o .= '<div class="col-sm-6"><div class="form-check "><input type="checkbox" class="form-check-input" id="f-' . e($n . '-' . $k) . '" name="' . e($n) . '[]" value="' . e($k) . '"' . (in_array((string) $k, $picked, true) ? ' checked' : '') . '><label class="form-check-label fw-normal" for="f-' . e($n . '-' . $k) . '">' . e($l) . '</label></div></div>';
            }
            return '<div class="mb-3"><label>' . e($f['label']) . '</label><input type="hidden" name="' . e($n) . '_present" value="1"><div class="row small">' . $o . '</div>' . $help . '</div>';
        case 'number':
            $in = '<input type="number" step="any" id="f-' . e($n) . '" name="' . e($n) . '" class="form-control" value="' . e($v) . '"' . (isset($f['min']) ? ' min="' . e($f['min']) . '"' : '') . (isset($f['max']) ? ' max="' . e($f['max']) . '"' : '') . '>';
            if (!empty($f['suffix'])) {
                $in = '<div class="input-group" style="max-width:260px">' . $in . '<span class="input-group-text">' . e($f['suffix']) . '</span></div>';
            }
            return '<div class="mb-3"><label for="f-' . e($n) . '">' . e($f['label']) . '</label>' . $in . $help . '</div>';
        default:
            $warn = $f['type'] === 'url' && str_starts_with(strtolower((string) $v), 'http://') ? '<small class="text-danger"><i class="fas fa-triangle-exclamation me-1"></i>Not encrypted: the API key crosses the network in plain text.</small>' : '';
            return '<div class="mb-3"><label for="f-' . e($n) . '">' . e($f['label']) . '</label><input type="' . ($f['type'] === 'url' ? 'url' : 'text') . '" id="f-' . e($n) . '" name="' . e($n) . '" class="form-control" value="' . e($v) . '" placeholder="' . e($f['placeholder'] ?? '') . '" autocomplete="off">' . $warn . $help . '</div>';
    }
})($f);
