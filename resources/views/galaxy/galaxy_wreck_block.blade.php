@php
    $tooltip = '<table role="presentation" width="240">'
        . '<tr><td class="c" colspan="2">' . e(__('game/galaxy.gl_wreck_field')) . ' [' . (int) $galaxy . ':' . (int) $system . ':' . (int) $planet . ']</td></tr>'
        . '<tr><th width="80"><img src="' . e($image) . '" height="75" width="75" alt=""></th>'
        . '<th><table>';

    foreach ($ships as $ship) {
        $tooltip .= '<tr><th scope="row">' . e($ship['name']) . ': </th><th role="cell">' . e($ship['amount']) . '</th></tr>';
    }

    $tooltip .= '<tr><td class="c" colspan="2">' . e($state) . '</td></tr>';

    if ($dock_url !== '') {
        $tooltip .= '<tr><th role="cell" colspan="2" align="left"><a href="' . e($dock_url) . '">' . e(__('game/spacedock.sd_title')) . '</a></th></tr>';
    }

    $tooltip .= '</table></th></tr></table>';
@endphp

<a
    style="cursor: pointer;"
    onmouseover='return overlib(@json($tooltip), STICKY, MOUSEOFF, DELAY, 750, CENTER, OFFSETX, -40, OFFSETY, -40);'
    onmouseout="return nd();">
    <img src="{{ $image }}" height="22px" width="22px" alt="{{ __('game/galaxy.gl_wreck_field') }}"/>
</a>
