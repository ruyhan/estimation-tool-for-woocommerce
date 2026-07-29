<?php
/**
 * Quotely UI — shared admin component API (free + Pro).
 *
 * Renders the design-system primitives as inline HTML so every admin screen
 * is built from the same vocabulary: modern SVG icons, cards, toggles, stat
 * cards, badges and buttons. Pro inherits this class from the free plugin.
 *
 * Icons are inline, stroke-based SVGs (Lucide-style) coloured via
 * `currentColor` — crisp at any size, theme-aware, no image files.
 *
 * @package quotely-estimates-for-woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Quotely_UI')) {

    class Quotely_UI {

        /**
         * Inner SVG markup for each icon (24×24 viewBox, stroke = currentColor).
         * Curated set covering navigation, actions and every feature.
         *
         * @return array<string,string>
         */
        private static function icon_paths() {
            return array(
                // Navigation
                'dashboard'   => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
                'chart'       => '<path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6" rx="1"/><rect x="12" y="8" width="3" height="10" rx="1"/><rect x="17" y="5" width="3" height="13" rx="1"/>',
                'file-text'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h8"/><path d="M8 9h2"/>',
                'mail'        => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
                'sliders'     => '<line x1="4" x2="4" y1="21" y2="14"/><line x1="4" x2="4" y1="10" y2="3"/><line x1="12" x2="12" y1="21" y2="12"/><line x1="12" x2="12" y1="8" y2="3"/><line x1="20" x2="20" y1="21" y2="16"/><line x1="20" x2="20" y1="12" y2="3"/><line x1="1" x2="7" y1="14" y2="14"/><line x1="9" x2="15" y1="8" y2="8"/><line x1="17" x2="23" y1="16" y2="16"/>',
                'key'         => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.5 12.5 8-8"/><path d="m17 4 3 3"/><path d="m15 6 3 3"/>',
                'tools'       => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
                // Actions
                'plus'        => '<path d="M5 12h14"/><path d="M12 5v14"/>',
                'check'       => '<path d="M20 6 9 17l-5-5"/>',
                'x'           => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
                'download'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>',
                'upload'      => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/>',
                'edit'        => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/>',
                'trash'       => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
                'copy'        => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
                'external'    => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
                'search'      => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
                'filter'      => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
                'calendar'    => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
                'chevron-right'=> '<path d="m9 18 6-6-6-6"/>',
                'chevron-down'=> '<path d="m6 9 6 6 6-6"/>',
                'save'        => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/>',
                // Feature icons
                'tag'         => '<path d="M12.6 2.6A2 2 0 0 0 11.2 2H4a2 2 0 0 0-2 2v7.2a2 2 0 0 0 .6 1.4l8.8 8.8a2 2 0 0 0 2.8 0l7.2-7.2a2 2 0 0 0 0-2.8z"/><circle cx="7.5" cy="7.5" r="1.2"/>',
                'percent'     => '<line x1="19" x2="5" y1="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
                'truck'       => '<path d="M5 18H3a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h11a1 1 0 0 1 1 1v11"/><path d="M15 9h4l3 3v5a1 1 0 0 1-1 1h-2"/><circle cx="7.5" cy="18.5" r="2"/><circle cx="17.5" cy="18.5" r="2"/>',
                'paperclip'   => '<path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
                'shield'      => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
                'users'       => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
                'zap'         => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
                'card'        => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>',
                'clock'       => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
                'bell'        => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
                'layers'      => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
                'branch'      => '<line x1="6" x2="6" y1="3" y2="15"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/>',
                'palette'     => '<circle cx="13.5" cy="6.5" r="1.5"/><circle cx="17.5" cy="10.5" r="1.5"/><circle cx="8.5" cy="7.5" r="1.5"/><circle cx="6.5" cy="12.5" r="1.5"/><path d="M12 2a10 10 0 0 0 0 20 2.5 2.5 0 0 0 2-4 2.5 2.5 0 0 1 2-4h2a4 4 0 0 0 4-4 10 10 0 0 0-10-8z"/>',
                'award'       => '<circle cx="12" cy="8" r="6"/><path d="M15.5 13.6 17 22l-5-3-5 3 1.5-8.4"/>',
                'user'        => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
                'user-check'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/>',
                'dollar'      => '<line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
                'trending-up' => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',
                'trending-down'=> '<polyline points="22 17 13.5 8.5 8.5 13.5 2 7"/><polyline points="16 17 22 17 22 11"/>',
                'repeat'      => '<path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>',
                'check-circle'=> '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>',
                'alert'       => '<path d="m21.73 18-8-14a2 2 0 0 0-3.46 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/>',
                'info'        => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
                'lock'        => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
                'eye'         => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
                'globe'       => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
            );
        }

        /**
         * Render a modern inline SVG icon.
         *
         * @param string $name  Icon key (see icon_paths()).
         * @param int    $size  Pixel size (square).
         * @param string $class Extra CSS classes.
         * @return string SVG markup, or empty string if the icon is unknown.
         */
        public static function icon($name, $size = 20, $class = '') {
            $paths = self::icon_paths();
            $name  = (string) $name;
            if (!isset($paths[$name])) {
                return '';
            }
            return sprintf(
                '<svg class="qly-icon %s" width="%d" height="%d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
                esc_attr($class),
                (int) $size,
                (int) $size,
                self::icon_paths()[$name] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static internal SVG markup, not user input.
            );
        }

        /** List of available icon names (for docs / tests). */
        public static function icon_names() {
            return array_keys(self::icon_paths());
        }

        /* ----------------------------------------------------------------
         * Component helpers
         * ---------------------------------------------------------------- */

        /**
         * A pill badge. $variant: pro|success|warning|danger|info|neutral|''.
         */
        public static function badge($label, $variant = '', $icon = '') {
            $cls = 'qly-badge' . ($variant ? ' qly-badge--' . sanitize_html_class($variant) : '');
            return sprintf(
                '<span class="%s">%s%s</span>',
                esc_attr($cls),
                $icon ? self::icon($icon, 13) : '',
                esc_html($label)
            );
        }

        /**
         * A modern toggle switch bound to a settings checkbox.
         *
         * @param string $name    Field name (e.g. estitofo[enable_x]).
         * @param bool   $checked Current state.
         * @param string $label   Visible label (optional).
         */
        public static function toggle($name, $checked = false, $label = '') {
            return sprintf(
                '<label class="qly-toggle"><input type="checkbox" name="%s" value="1" %s><span class="qly-toggle__track"></span>%s</label>',
                esc_attr($name),
                checked($checked, true, false),
                $label ? '<span class="qly-toggle__label">' . esc_html($label) . '</span>' : ''
            );
        }

        /**
         * A button. $variant: primary|accent|secondary|ghost|danger.
         */
        public static function button($label, $variant = 'primary', $args = array()) {
            $tag   = isset($args['href']) ? 'a' : 'button';
            $icon  = isset($args['icon']) ? self::icon($args['icon'], 16) : '';
            $size  = isset($args['size']) ? ' qly-btn--' . sanitize_html_class($args['size']) : '';
            $attrs = '';
            if (isset($args['href'])) {
                $attrs .= ' href="' . esc_url($args['href']) . '"';
                if (!empty($args['blank'])) {
                    $attrs .= ' target="_blank" rel="noopener"';
                }
            } else {
                $attrs .= ' type="' . esc_attr($args['type'] ?? 'button') . '"';
            }
            if (!empty($args['attrs'])) {
                $attrs .= ' ' . $args['attrs']; // pre-escaped by caller
            }
            return sprintf(
                '<%1$s class="qly-btn qly-btn--%2$s%3$s"%4$s>%5$s%6$s</%1$s>',
                $tag,
                sanitize_html_class($variant),
                $size,
                $attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped above.
                $icon,
                esc_html($label)
            );
        }

        /**
         * A KPI / stat card.
         *
         * @param array $args label, value, trend, trend_dir(up|down), gradient(g1..g5), icon
         */
        public static function stat($args) {
            $grad  = !empty($args['gradient']) ? ' qly-stat--gradient qly-stat--' . sanitize_html_class($args['gradient']) : '';
            $trend = '';
            if (isset($args['trend']) && '' !== $args['trend']) {
                $dir  = ($args['trend_dir'] ?? 'up') === 'down' ? 'is-down' : 'is-up';
                $ic   = 'is-down' === $dir ? 'trending-down' : 'trending-up';
                $trend = sprintf('<span class="qly-stat__trend %s">%s%s</span>', $dir, self::icon($ic, 12), esc_html($args['trend']));
            }
            return sprintf(
                '<div class="qly-stat%s"><div class="qly-stat__label">%s</div><div class="qly-stat__value">%s</div>%s</div>',
                $grad,
                esc_html($args['label'] ?? ''),
                esc_html($args['value'] ?? ''),
                $trend
            );
        }

        /**
         * Open a card. Pair with card_close(). $args: title, icon, badge, class.
         */
        public static function card_open($args = array()) {
            $head = '';
            if (!empty($args['title'])) {
                $icon  = !empty($args['icon']) ? '<span class="qly-card__icon">' . self::icon($args['icon'], 18) . '</span>' : '';
                $badge = !empty($args['badge']) ? self::badge($args['badge'], 'pro') : '';
                $head  = sprintf(
                    '<div class="qly-card__head">%s<h3>%s</h3>%s</div>',
                    $icon,
                    esc_html($args['title']),
                    $badge ? '<span style="margin-left:auto">' . $badge . '</span>' : ''
                );
            }
            $cls = 'qly-card' . (!empty($args['class']) ? ' ' . $args['class'] : '');
            return '<section class="' . esc_attr($cls) . '">' . $head . '<div class="qly-card__body">';
        }

        public static function card_close() {
            return '</div></section>';
        }
    }
}
