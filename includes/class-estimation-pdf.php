<?php
if (!defined('ABSPATH')) {
    exit;
}

class Estitofo_PDF {

    /** @var array RGB triple of the brand primary colour (e.g. for the header band). */
    private $brand = array(37, 99, 235);
    /** @var array RGB triple of the brand accent colour. */
    private $accent = array(30, 64, 175);
    /** @var string */
    private $currency = '';
    /** @var string */
    private $template = 'classic';
    /**
     * Font family used throughout the PDF.
     *
     * We default to TCPDF's core 'helvetica' because:
     *   - It's a core PDF14 font: no external font file to load, no AddFont(),
     *     no font-subsetting machinery — works on every host regardless of
     *     GD/Imagick/freetype availability.
     *   - It avoids the PHP 8.2+ "Undefined property" warnings that newer PHP
     *     emits when TCPDF reads its TTF-subset state without GD installed.
     *
     * Non-Latin currency symbols (BDT ৳, INR ₹, KRW ₩, …) that helvetica
     * can't render are mapped to their ISO code instead via money().
     *
     * @var string
     */
    private $font = 'helvetica';

    public function generate($products, $total, $user_info = array()) {
        if (!function_exists('get_woocommerce_currency')) {
            throw new Exception(esc_html__('WooCommerce is required for PDF generation', 'estimation-tool-for-woocommerce'));
        }
        if (!class_exists('TCPDF')) {
            throw new Exception(esc_html__('TCPDF library is not loaded', 'estimation-tool-for-woocommerce'));
        }

        $this->currency = get_woocommerce_currency();
        $primary_hex    = (string) Estitofo_Options::get('primary_color', '#2563eb');
        $accent_hex     = (string) Estitofo_Options::get('accent_color', '#1e40af');
        $this->brand    = self::hex_to_rgb($primary_hex ?: '#2563eb');
        $this->accent   = self::hex_to_rgb($accent_hex ?: '#1e40af');

        $company_name = (string) Estitofo_Options::get('company_name', '');
        if ($company_name === '') {
            $company_name = (string) get_bloginfo('name');
        }
        $tagline       = (string) Estitofo_Options::get('company_tagline', '');
        $pdf_author    = (string) Estitofo_Options::get('pdf_author', '');
        if ($pdf_author === '') {
            $pdf_author = $company_name;
        }
        $pdf_author    = (string) apply_filters('estitofo_pdf_author', $pdf_author);
        $this->template = (string) apply_filters('estitofo_pdf_template', 'classic');
        $logo_url      = (string) Estitofo_Options::get('logo_url', '');
        $footer_text   = (string) Estitofo_Options::get('footer_text', '');
        $locations     = Estitofo_Settings::get_locations_array();

        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        // TCPDF 6.10 + PHP 8.2 + opcache: some properties declared with default
        // values (protected $fontkeys = array()) read as NULL at runtime because
        // the default never lands on the instance. The constructor doesn't
        // reassign them, so the first AddFont() call hits `in_array($x, NULL)`.
        // This guard force-initialises every property TCPDF later reads but
        // never explicitly writes in its constructor.
        self::init_tcpdf_props($pdf);

        try {
            $pdf->setProtection(array('modify', 'copy', 'annot-forms', 'fill-forms', 'extract', 'assemble', 'print-highres'), '');
        } catch (Exception $e) { /* encryption unavailable — continue */ }

        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor($pdf_author);
        $pdf->SetTitle(__('Product Estimation', 'estimation-tool-for-woocommerce'));
        $pdf->SetSubject(__('Product Estimation', 'estimation-tool-for-woocommerce'));
        $pdf->SetKeywords('PDF, Quotation, Estimation');

        $pdf->SetMargins(15, 52, 15);
        $pdf->SetAutoPageBreak(true, 25);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        $pdf->SetFont($this->font, '', 10);
        $pdf->AddPage();

        $hide_logo = ($this->template === 'minimal');

        // ---- Header band ----
        $this->draw_header_band($pdf, $company_name, $tagline, $logo_url, $hide_logo);

        // ---- Title and meta ----
        $this->draw_title_block($pdf, $user_info);

        // ---- Bill To ----
        $this->draw_billto_block($pdf, $user_info);

        // ---- Products table ----
        $this->draw_products_table($pdf, $products);

        // ---- Total panel ----
        $this->draw_total_panel($pdf, (float) $total, $products);

        // ---- Notes / footer ----
        if ($footer_text) {
            $pdf->Ln(8);
            $pdf->SetFont($this->font, 'I', 9);
            $pdf->SetTextColor(110, 110, 110);
            $pdf->MultiCell(0, 5, $footer_text, 0, 'C');
            $pdf->SetTextColor(0, 0, 0);
        }

        // Locations strip at the bottom of the last page.
        if (!empty($locations)) {
            $this->draw_locations_strip($pdf, $locations, $company_name);
        }

        // Page number at the very bottom.
        $this->draw_page_numbers($pdf);

        return $pdf;
    }

    private function draw_header_band($pdf, $company_name, $tagline, $logo_url, $hide_logo) {
        $page_w = $pdf->getPageWidth();
        list($r, $g, $b) = $this->brand;
        list($ar, $ag, $ab) = $this->accent;

        // Full-width gradient-style band: solid primary on the left, darker
        // accent overlay sliding from right (mimics a diagonal gradient).
        $pdf->SetFillColor($r, $g, $b);
        $pdf->Rect(0, 0, $page_w, 38, 'F');

        // Diagonal accent triangle on the right for depth.
        $pdf->SetFillColor($ar, $ag, $ab);
        $pdf->Polygon(array(
            $page_w,        0,
            $page_w,        38,
            $page_w - 80,   38,
        ), 'F');

        // Razor-thin lighter strip just below the band for that "premium" feel.
        $pdf->SetFillColor(
            min(255, $r + 40),
            min(255, $g + 40),
            min(255, $b + 40)
        );
        $pdf->Rect(0, 38, $page_w, 1.2, 'F');

        $logo_drawn = false;
        if (!$hide_logo) {
            $embed = self::load_logo_data($logo_url);
            // Attempt 1: raw-bytes embed via '@' prefix.
            if (is_array($embed) && !empty($embed['data']) && !empty($embed['type'])) {
                try {
                    $pdf->Image(
                        '@' . $embed['data'],
                        15, 6, 0, 25, $embed['type'],
                        '', 'T', false, 300, '', false, false, 0, false, false, false
                    );
                    $logo_drawn = true;
                } catch (Exception $e) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[wc-estimation] PDF logo @-embed failed: ' . $e->getMessage());
                    }
                }
            }
            // Attempt 2: path-based embed if @-embed didn't fire (e.g. couldn't read bytes).
            if (!$logo_drawn) {
                $path = $this->resolve_logo_path($logo_url);
                if ($path && file_exists($path)) {
                    $ext = strtoupper(pathinfo($path, PATHINFO_EXTENSION));
                    if (in_array($ext, array('JPG', 'JPEG', 'PNG'), true)) {
                        try {
                            $pdf->Image(
                                $path,
                                15, 6, 0, 25,
                                $ext === 'JPEG' ? 'JPG' : $ext,
                                '', 'T', false, 300, '', false, false, 0, false, false, false
                            );
                            $logo_drawn = true;
                        } catch (Exception $e) {
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                error_log('[wc-estimation] PDF logo path-embed failed: ' . $e->getMessage() . ' (path: ' . $path . ')');
                            }
                        }
                    }
                } elseif (defined('WP_DEBUG') && WP_DEBUG && ($logo_url || (int) Estitofo_Options::get('logo_id', 0))) {
                    error_log('[wc-estimation] PDF logo: resolve_logo_path returned nothing. URL=' . $logo_url
                        . ' | logo_id=' . (int) Estitofo_Options::get('logo_id', 0));
                }
            }
        }

        // Company name (white text on band) — to the right of the logo, or left-aligned if no logo.
        $x = $logo_drawn ? 50 : 15;
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont($this->font, 'B', 18);
        $pdf->SetXY($x, 9);
        $pdf->Cell($page_w - $x - 15, 9, $company_name, 0, 1, 'L');
        if ($tagline) {
            $pdf->SetFont($this->font, '', 10);
            $pdf->SetX($x);
            $pdf->Cell($page_w - $x - 15, 6, $tagline, 0, 1, 'L');
        }
        $pdf->SetTextColor(0, 0, 0);
    }

    private function draw_title_block($pdf, $user_info) {
        $title_y = 48;
        $pdf->SetY($title_y);
        list($r, $g, $b) = $this->brand;

        // ---- Big title on the left.
        $pdf->SetFont($this->font, 'B', 24);
        $pdf->SetTextColor($r, $g, $b);
        $pdf->SetXY(15, $title_y);
        $pdf->Cell(110, 12, strtoupper(__('Estimation', 'estimation-tool-for-woocommerce')), 0, 0, 'L');

        // ---- Meta on the right, vertically centred against the title.
        $date = !empty($user_info['date'])
            ? date_i18n(get_option('date_format'), strtotime($user_info['date']))
            : date_i18n(get_option('date_format'));
        $ref  = !empty($user_info['ref']) ? (string) $user_info['ref'] : '';

        $pdf->SetFont($this->font, '', 10);
        $pdf->SetTextColor(80, 80, 80);
        $meta_x = 125;
        $meta_w = 70;
        $meta_y = $title_y + 1;
        $pdf->SetXY($meta_x, $meta_y);
        $pdf->Cell($meta_w, 5, __('Date', 'estimation-tool-for-woocommerce') . ': ' . $date, 0, 1, 'R');
        if ($ref !== '') {
            $pdf->SetXY($meta_x, $meta_y + 5);
            $pdf->Cell($meta_w, 5, __('Reference', 'estimation-tool-for-woocommerce') . ': ' . $ref, 0, 1, 'R');
        }

        // Move the cursor *below* the title block so the next section doesn't overlap.
        $pdf->SetY($title_y + 14);
        $pdf->SetTextColor(0, 0, 0);
    }

    private function draw_billto_block($pdf, $user_info) {
        $x = 15;
        $y = $pdf->GetY();
        $w = 180;
        $h = 26;

        $pdf->SetDrawColor(230, 230, 230);
        $pdf->SetFillColor(248, 250, 252);
        $pdf->RoundedRect($x, $y, $w, $h, 2.5, '1111', 'DF');

        list($r, $g, $b) = $this->brand;
        $pdf->SetFont($this->font, 'B', 9);
        $pdf->SetTextColor($r, $g, $b);
        $pdf->SetXY($x + 5, $y + 4);
        $pdf->Cell(50, 4, strtoupper(__('Bill To', 'estimation-tool-for-woocommerce')), 0, 1);

        $pdf->SetFont($this->font, 'B', 11);
        $pdf->SetTextColor(20, 20, 20);
        $pdf->SetXY($x + 5, $y + 9);
        $pdf->Cell(110, 5, !empty($user_info['name']) ? sanitize_text_field($user_info['name']) : '—', 0, 1);

        $pdf->SetFont($this->font, '', 10);
        $pdf->SetTextColor(80, 80, 80);
        $email = !empty($user_info['email']) ? sanitize_email($user_info['email']) : '';
        $phone = !empty($user_info['phone']) ? sanitize_text_field($user_info['phone']) : '';
        $pdf->SetXY($x + 5, $y + 15);
        if ($email) {
            $pdf->Cell(110, 4.5, $email, 0, 1);
        }
        if ($phone) {
            $pdf->SetX($x + 5);
            $pdf->Cell(110, 4.5, $phone, 0, 1);
        }
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetY($y + $h + 6);
    }

    private function draw_products_table($pdf, $products) {
        $col_img = 22;
        $col_qty = 18;
        $col_price = 30;
        $col_sub = 32;
        $col_title = 180 - $col_img - $col_qty - $col_price - $col_sub; // 78

        // Header row
        list($r, $g, $b) = $this->brand;
        $pdf->SetFillColor($r, $g, $b);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont($this->font, 'B', 10);
        $head_h = 9;
        $pdf->Cell($col_img,   $head_h, '',                                                       0, 0, 'C', 1);
        $pdf->Cell($col_title, $head_h, __('Product',  'estimation-tool-for-woocommerce'),       0, 0, 'L', 1);
        $pdf->Cell($col_qty,   $head_h, __('Qty',      'estimation-tool-for-woocommerce'),       0, 0, 'C', 1);
        $pdf->Cell($col_price, $head_h, __('Price',    'estimation-tool-for-woocommerce'),       0, 0, 'R', 1);
        $pdf->Cell($col_sub,   $head_h, __('Subtotal', 'estimation-tool-for-woocommerce'),       0, 1, 'R', 1);

        $pdf->SetTextColor(20, 20, 20);
        $pdf->SetFont($this->font, '', 10);

        $zebra = false;
        foreach ($products as $product) {
            if (!is_array($product)) continue;

            $title    = isset($product['title']) ? sanitize_text_field($product['title']) : '';
            $price    = isset($product['price']) ? floatval($product['price']) : 0;
            $quantity = isset($product['quantity']) ? max(1, absint($product['quantity'])) : 1;
            $image    = isset($product['image']) ? esc_url_raw($product['image']) : '';
            $note     = isset($product['note']) ? sanitize_text_field($product['note']) : '';
            $subtotal = $price * $quantity;

            // Row height — based on title length (+ optional note line).
            $title_h = $pdf->getStringHeight($col_title - 4, $title, false, true, '', 1);
            $row_h   = max(18, $title_h + 6 + ($note !== '' ? 5 : 0));

            // Page break check.
            if ($pdf->GetY() + $row_h > ($pdf->getPageHeight() - $pdf->getBreakMargin())) {
                $pdf->AddPage();
                $this->draw_header_band($pdf, '', '', '', true); // continuation: blank/minimal band
                $pdf->SetY(48);
                $pdf->SetFillColor($r, $g, $b);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont($this->font, 'B', 10);
                $pdf->Cell($col_img,   $head_h, '',                                            0, 0, 'C', 1);
                $pdf->Cell($col_title, $head_h, __('Product',  'estimation-tool-for-woocommerce'), 0, 0, 'L', 1);
                $pdf->Cell($col_qty,   $head_h, __('Qty',      'estimation-tool-for-woocommerce'), 0, 0, 'C', 1);
                $pdf->Cell($col_price, $head_h, __('Price',    'estimation-tool-for-woocommerce'), 0, 0, 'R', 1);
                $pdf->Cell($col_sub,   $head_h, __('Subtotal', 'estimation-tool-for-woocommerce'), 0, 1, 'R', 1);
                $pdf->SetTextColor(20, 20, 20);
                $pdf->SetFont($this->font, '', 10);
            }

            $start_x = 15;
            $start_y = $pdf->GetY();

            // Row background (zebra).
            $pdf->SetFillColor($zebra ? 248 : 255, $zebra ? 250 : 255, $zebra ? 252 : 255);
            $pdf->Rect($start_x, $start_y, 180, $row_h, 'F');

            // Subtle bottom border for the row.
            $pdf->SetDrawColor(230, 230, 230);
            $pdf->Line($start_x, $start_y + $row_h, $start_x + 180, $start_y + $row_h);

            // ---- Image cell (drawn at absolute pos so it never breaks the cell cursor) ----
            $this->draw_row_image($pdf, $image, $start_x, $start_y, $col_img, $row_h);

            // ---- Title (with optional note underneath) — written with absolute SetXY ----
            $pdf->SetXY($start_x + $col_img + 2, $start_y + 3);
            $pdf->SetFont($this->font, 'B', 10);
            $pdf->SetTextColor(20, 20, 20);
            $pdf->MultiCell($col_title - 4, 5, $title, 0, 'L');
            if ($note !== '') {
                $pdf->SetX($start_x + $col_img + 2);
                $pdf->SetFont($this->font, 'I', 8);
                $pdf->SetTextColor(110, 110, 110);
                $pdf->MultiCell($col_title - 4, 4, $note, 0, 'L');
            }

            // ---- Qty / Price / Subtotal — vertical center via SetXY ----
            $val_y = $start_y + ($row_h - 5) / 2;

            $pdf->SetFont($this->font, '', 10);
            $pdf->SetTextColor(40, 40, 40);
            $pdf->SetXY($start_x + $col_img + $col_title, $val_y);
            $pdf->Cell($col_qty, 5, (string) $quantity, 0, 0, 'C');

            $pdf->SetXY($start_x + $col_img + $col_title + $col_qty, $val_y);
            $pdf->Cell($col_price, 5, $this->money($price), 0, 0, 'R');

            $pdf->SetFont($this->font, 'B', 10);
            $pdf->SetXY($start_x + $col_img + $col_title + $col_qty + $col_price, $val_y);
            $pdf->Cell($col_sub, 5, $this->money($subtotal), 0, 0, 'R');

            // Advance cursor past the row.
            $pdf->SetXY($start_x, $start_y + $row_h);

            $zebra = !$zebra;
        }
    }

    private function draw_row_image($pdf, $image_url, $cell_x, $cell_y, $cell_w, $cell_h) {
        $image_path = $image_url ? $this->url_to_path($image_url) : false;

        // Fallback 1: WooCommerce's placeholder.
        if (!$image_path || !file_exists($image_path)) {
            if (function_exists('wc_placeholder_img_src')) {
                $ph_url  = wc_placeholder_img_src('thumbnail');
                $ph_path = $this->url_to_path($ph_url);
                if ($ph_path && file_exists($ph_path)) {
                    $image_path = $ph_path;
                }
            }
        }

        // Fallback 2: draw a vector placeholder right inside the cell so the
        // column visually balances even when nothing else worked.
        if (!$image_path || !file_exists($image_path)) {
            $this->draw_vector_placeholder($pdf, $cell_x, $cell_y, $cell_w, $cell_h);
            return;
        }

        $ext = strtoupper(pathinfo($image_path, PATHINFO_EXTENSION));
        if (!in_array($ext, array('JPG', 'JPEG', 'PNG', 'GIF'), true)) {
            $this->draw_vector_placeholder($pdf, $cell_x, $cell_y, $cell_w, $cell_h);
            return;
        }

        $safe = $this->safe_image_path($image_path, $ext);
        if (!$safe) return;
        $safe_ext = strtoupper(pathinfo($safe, PATHINFO_EXTENSION));

        // Constrain image to a square box, centered in the cell, with a small inset.
        $box = min($cell_w, $cell_h) - 4;
        if ($box <= 0) return;
        $img_x = $cell_x + ($cell_w - $box) / 2;
        $img_y = $cell_y + ($cell_h - $box) / 2;

        try {
            // Pass both width AND height with fitbox=true so TCPDF preserves aspect and never overflows.
            $pdf->Image(
                $safe,
                $img_x, $img_y,
                $box, $box,
                $safe_ext === 'JPEG' ? 'JPG' : $safe_ext,
                '',
                'T',
                false,
                300,
                '',
                false,
                false,
                0,    // border
                'CM', // fitbox: Center horizontally, Middle vertically
                false,
                false
            );
        } catch (Exception $e) {
            // Image embed failed — draw the vector placeholder so the cell isn't blank.
            $this->draw_vector_placeholder($pdf, $cell_x, $cell_y, $cell_w, $cell_h);
        }
    }

    /**
     * Draw a self-contained placeholder rectangle with a tiny camera glyph
     * inside the image cell. Used when:
     *   - the product has no featured image,
     *   - the image URL doesn't resolve to a local file, AND
     *   - the WooCommerce placeholder file isn't found either.
     * This never relies on external assets, so it always renders.
     */
    private function draw_vector_placeholder($pdf, $cell_x, $cell_y, $cell_w, $cell_h) {
        $box = min($cell_w, $cell_h) - 4;
        if ($box <= 0) return;
        $x = $cell_x + ($cell_w - $box) / 2;
        $y = $cell_y + ($cell_h - $box) / 2;

        // Soft grey rounded rectangle.
        $pdf->SetDrawColor(220, 220, 220);
        $pdf->SetFillColor(240, 242, 245);
        $pdf->RoundedRect($x, $y, $box, $box, 1.5, '1111', 'DF');

        // Simple camera/picture icon in the centre.
        $cx = $x + $box / 2;
        $cy = $y + $box / 2;
        $pdf->SetDrawColor(170, 175, 185);
        $pdf->SetFillColor(170, 175, 185);
        // Mountain triangle.
        $pdf->Polygon(array(
            $cx - $box * 0.30, $cy + $box * 0.15,
            $cx - $box * 0.05, $cy - $box * 0.10,
            $cx + $box * 0.20, $cy + $box * 0.15,
        ), 'F');
        // Sun dot.
        $pdf->Circle($cx + $box * 0.18, $cy - $box * 0.12, $box * 0.04, 0, 360, 'F');
    }

    private function draw_total_panel($pdf, $total, $products) {
        $pdf->Ln(10);
        $item_count = 0;
        foreach ($products as $p) {
            if (is_array($p)) {
                $item_count += isset($p['quantity']) ? max(1, absint($p['quantity'])) : 1;
            }
        }

        $box_w  = 92;
        $box_x  = 15 + 180 - $box_w;
        $box_y  = $pdf->GetY();
        $line_h = 8;
        $box_h  = $line_h * 2 + 6 + 16;  // subtotal row + items row + spacing + total stripe

        list($r, $g, $b) = $this->brand;

        // Outer card.
        $pdf->SetDrawColor(225, 230, 240);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect($box_x, $box_y, $box_w, $box_h, 3, '1111', 'DF');

        // Top portion: items + subtotal.
        $pdf->SetFont($this->font, '', 10);
        $pdf->SetTextColor(100, 110, 125);
        $pdf->SetXY($box_x + 7, $box_y + 4);
        $pdf->Cell($box_w / 2 - 7, $line_h, __('Items', 'estimation-tool-for-woocommerce'), 0, 0, 'L');
        $pdf->SetFont($this->font, '', 10);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->SetXY($box_x + $box_w / 2, $box_y + 4);
        $pdf->Cell($box_w / 2 - 7, $line_h, (string) $item_count, 0, 1, 'R');

        $pdf->SetFont($this->font, '', 10);
        $pdf->SetTextColor(100, 110, 125);
        $pdf->SetXY($box_x + 7, $box_y + 4 + $line_h);
        $pdf->Cell($box_w / 2 - 7, $line_h, __('Subtotal', 'estimation-tool-for-woocommerce'), 0, 0, 'L');
        $pdf->SetFont($this->font, '', 10);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->SetXY($box_x + $box_w / 2, $box_y + 4 + $line_h);
        $pdf->Cell($box_w / 2 - 7, $line_h, $this->money($total), 0, 1, 'R');

        // Brand-coloured TOTAL stripe at the bottom of the card.
        $stripe_y = $box_y + 4 + $line_h * 2 + 3;
        $stripe_h = $box_h - ($stripe_y - $box_y) - 1; // fill to bottom-inside
        $pdf->SetFillColor($r, $g, $b);
        $pdf->RoundedRect($box_x + 1, $stripe_y, $box_w - 2, $stripe_h, 2, '0011', 'F');

        $pdf->SetFont($this->font, 'B', 14);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY($box_x + 7, $stripe_y + ($stripe_h - 8) / 2);
        $pdf->Cell($box_w / 2 - 7, 8, __('TOTAL', 'estimation-tool-for-woocommerce'), 0, 0, 'L');
        $pdf->SetXY($box_x + $box_w / 2, $stripe_y + ($stripe_h - 8) / 2);
        $pdf->Cell($box_w / 2 - 7, 8, $this->money($total), 0, 1, 'R');

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetY($box_y + $box_h + 6);
    }

    private function draw_locations_strip($pdf, $locations, $company_name) {
        $pageCount = $pdf->getNumPages();
        $pdf->setPage($pageCount);

        // Disable auto-page-break for the footer strip — we're positioning
        // explicitly near the bottom of the page, which would otherwise cross
        // the break threshold and spawn a blank page.
        $pdf->SetAutoPageBreak(false, 0);

        $pdf->SetY(-30);
        $pdf->SetDrawColor(220, 220, 220);
        $pdf->Line(15, $pdf->GetY(), $pdf->getPageWidth() - 15, $pdf->GetY());
        $pdf->Ln(2);

        if ($company_name) {
            $pdf->SetFont($this->font, 'B', 9);
            $pdf->SetTextColor(60, 60, 60);
            $pdf->Cell(0, 5, $company_name, 0, 1, 'C');
        }

        $pdf->SetFont($this->font, '', 8);
        $pdf->SetTextColor(100, 100, 100);

        $cellWidth = 180 / max(1, count($locations));
        $pdf->SetX(15);
        foreach ($locations as $loc) {
            $pdf->Cell($cellWidth, 4.5, $loc['name'], 0, 0, 'C');
        }
        $pdf->Ln(4.5);
        $pdf->SetX(15);
        foreach ($locations as $loc) {
            $pdf->Cell($cellWidth, 4.5, $loc['phone'], 0, 0, 'C');
        }
        $pdf->SetTextColor(0, 0, 0);
    }

    private function draw_page_numbers($pdf) {
        $count = $pdf->getNumPages();
        // Skip when there's only one page — no point printing "Page 1 of 1".
        if ($count < 2) {
            return;
        }
        // Disable auto-page-break before writing footer items; otherwise the
        // SetY(-12) + Cell write at the bottom margin spawns a blank page.
        $pdf->SetAutoPageBreak(false, 0);
        for ($i = 1; $i <= $count; $i++) {
            $pdf->setPage($i);
            $pdf->SetY(-12);
            $pdf->SetFont($this->font, '', 8);
            $pdf->SetTextColor(150, 150, 150);
            $pdf->Cell(0, 4, sprintf(
                /* translators: 1: current page, 2: total pages */
                __('Page %1$d of %2$d', 'estimation-tool-for-woocommerce'),
                $i, $count
            ), 0, 0, 'C');
        }
        $pdf->SetTextColor(0, 0, 0);
    }

    private function money($amount) {
        // WooCommerce returns currency symbols HTML-entity-encoded (e.g. `&#2547;`
        // for BDT, `&pound;` for GBP). TCPDF prints the raw string, so we must
        // decode entities first or the literal `&#2547;` shows up in the PDF.
        $sym = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : $this->currency;
        $sym = html_entity_decode((string) $sym, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $sym = trim(str_replace(array("\xC2\xA0", "\xE2\x80\xAF"), '', $sym));

        // Currencies whose symbol falls outside dejavusans / helvetica glyph
        // coverage. For these we use the ISO code so the amount actually shows
        // up instead of an empty box (or worse, the literal HTML entity).
        // ৳ is Bengali script (U+09F3), ₹ ₱ ₩ ₫ ฿ ₮ etc. are also outside
        // dejavusans's coverage.
        $iso_only = array(
            'BDT', // ৳ taka
            'INR', // ₹ rupee
            'THB', // ฿ baht
            'KRW', // ₩ won
            'VND', // ₫ dong
            'PHP', // ₱ peso
            'RUB', // ₽ ruble
            'NGN', // ₦ naira
            'GHS', // ₵ cedi
            'PYG', // ₲ guarani
            'UAH', // ₴ hryvnia
            'KZT', // ₸ tenge
            'KHR', // ៛ riel
            'LAK', // ₭ kip
            'MNT', // ₮ tugrik
        );

        $code = strtoupper($this->currency);
        if ($sym === '' || in_array($code, $iso_only, true)) {
            $sym = $code;
        }

        // Add a thin gap if the symbol ends in a letter (ISO code, "kr", etc.).
        $glue = preg_match('/[A-Za-z]$/u', $sym) ? ' ' : '';
        return $sym . $glue . number_format((float) $amount, 2);
    }

    /**
     * Reflection-based safety net for TCPDF 6.10 on PHP 8.2 + opcache.
     *
     * Some TCPDF properties are declared with default values (e.g.
     * `protected $fontkeys = array();`) but never explicitly assigned in
     * `__construct()`. On certain PHP 8.2 + opcache combinations the class
     * defaults aren't materialised on the instance, so reading the property
     * returns NULL. The first `AddFont()` call then explodes with
     * `in_array(NULL)` inside `setFontBuffer()`. This pre-seeds every
     * property TCPDF later reads-before-writing, so the rest of the API
     * works regardless of the host's PHP/opcache configuration.
     */
    private static function init_tcpdf_props($pdf) {
        if (!class_exists('ReflectionClass')) return;
        $defaults = array(
            'fontkeys'        => array(),
            'numfonts'        => 0,
            'fonts'           => array(),
            'font_subsetting' => true,
            'pdfa_mode'       => false,
            'isunicode'       => true,
            'FillColor'       => '0 g',
            'DrawColor'       => '0 G',
            'TextColor'       => '0 g',
            'CoreFonts'       => array(
                'courier'      => 'Courier',
                'courierB'     => 'Courier-Bold',
                'courierI'     => 'Courier-Oblique',
                'courierBI'    => 'Courier-BoldOblique',
                'helvetica'    => 'Helvetica',
                'helveticaB'   => 'Helvetica-Bold',
                'helveticaI'   => 'Helvetica-Oblique',
                'helveticaBI'  => 'Helvetica-BoldOblique',
                'times'        => 'Times-Roman',
                'timesB'       => 'Times-Bold',
                'timesI'       => 'Times-Italic',
                'timesBI'      => 'Times-BoldItalic',
                'symbol'       => 'Symbol',
                'zapfdingbats' => 'ZapfDingbats',
            ),
            'font_obj_ids'    => array(),
            'pdflayers'       => array(),
            'images'          => array(),
            'links'           => array(),
            'gradients'       => array(),
            'diffs'           => array(),
            'FontFiles'       => array(),
            'transfmrk'       => array(0 => array()),
            'pagedim'         => array(),
            'pages'           => array(),
        );
        try {
            $ref = new ReflectionClass('TCPDF');
        } catch (Exception $e) {
            return;
        }
        foreach ($defaults as $name => $default) {
            if (!$ref->hasProperty($name)) continue;
            try {
                $prop = $ref->getProperty($name);
                $prop->setAccessible(true);
                $current = $prop->getValue($pdf);
                if ($current === null) {
                    $prop->setValue($pdf, $default);
                }
            } catch (Exception $e) { /* skip individual property failures */ }
        }
    }

    public static function hex_to_rgb($hex) {
        $hex = ltrim((string) $hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return array(37, 99, 235); // brand fallback
        }
        return array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }

    /**
     * Resolve the configured logo to a local file path.
     *
     * Two paths in order of reliability:
     *   1. attachment ID stored in option `logo_id` → get_attached_file() (rock solid).
     *   2. logo URL via url_to_path() (string parsing — can fail on quirky hosts).
     *
     * @param string $logo_url Fallback URL stored alongside the ID.
     * @return string|false Absolute file path that exists, or false.
     */
    /**
     * Load the configured logo and return raw image data ready for TCPDF.
     *
     * Why raw bytes instead of a file path: TCPDF's path-based `Image()` is
     * sensitive to host quirks — open_basedir, mixed slashes on Windows,
     * symlinked uploads dirs, hardened hosts that block reading certain
     * extensions, allow_url_fopen toggles, etc. Reading the file ourselves
     * into a string and passing it via the `@` prefix sidesteps all of that.
     *
     * Returns ['data' => string, 'type' => 'JPG'|'PNG'] on success, or false.
     */
    public static function load_logo_data($logo_url) {
        $instance = new self();
        $path = $instance->resolve_logo_path($logo_url);
        if (!$path || !file_exists($path) || !is_readable($path)) {
            return false;
        }
        $ext = strtoupper(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, array('JPG', 'JPEG', 'PNG'), true)) {
            return false;
        }
        // Flatten PNG-with-alpha into a JPG with white background where needed
        // (some TCPDF builds choke on PNG alpha).
        $safe = $instance->safe_image_path($path, $ext);
        if (!$safe || !file_exists($safe)) {
            return false;
        }
        $bytes = @file_get_contents($safe); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        if (!is_string($bytes) || $bytes === '') {
            return false;
        }
        $safe_ext = strtoupper(pathinfo($safe, PATHINFO_EXTENSION));
        $type = ($safe_ext === 'JPEG') ? 'JPG' : $safe_ext;
        return array('data' => $bytes, 'type' => $type, 'path' => $safe);
    }

    /**
     * Diagnostic readout for the admin PDF tab — exercises the *full* logo
     * embed chain (resolve → safe-image → read bytes) and reports a precise
     * status. Also performs a one-time auto-backfill of `logo_id` from
     * `logo_url` when the ID is missing (so older saved logos work without
     * the user having to re-pick them).
     */
    public static function logo_diagnostic() {
        $url = (string) Estitofo_Options::get('logo_url', '');
        $id  = (int) Estitofo_Options::get('logo_id', 0);

        // ---- Auto-backfill: if URL is set but ID is missing, ask WP for the ID.
        if ($url !== '' && $id === 0 && function_exists('attachment_url_to_postid')) {
            $resolved_id = attachment_url_to_postid($url);
            if (!$resolved_id) {
                // Strip size suffix and retry (e.g. -150x150).
                $bare = preg_replace('#-\d+x\d+(\.[a-z]+)$#i', '$1', $url);
                if ($bare && $bare !== $url) {
                    $resolved_id = attachment_url_to_postid($bare);
                }
            }
            if ($resolved_id) {
                Estitofo_Options::set('logo_id', (int) $resolved_id);
                $id = (int) $resolved_id;
            }
        }

        $detail = array(
            'logo_url'      => $url,
            'logo_id'       => $id,
            'resolved_path' => '',
            'readable'      => false,
            'size_bytes'    => 0,
            'ext'           => '',
            'gd_available'  => function_exists('imagecreatefrompng') ? 'yes' : 'no',
            'imagick'       => extension_loaded('imagick') ? 'yes' : 'no',
            'tcpdf_loaded'  => class_exists('TCPDF') ? 'yes' : 'no',
            'safe_path'     => '',
            'bytes_loaded'  => 0,
        );

        if ($url === '' && $id === 0) {
            return array('ok' => false, 'message' => __('No logo configured. Click "Choose Image" above.', 'estimation-tool-for-woocommerce'), 'detail' => $detail);
        }

        $instance = new self();
        $path = $instance->resolve_logo_path($url);
        $detail['resolved_path'] = $path ?: '(unresolved)';
        if (!$path) {
            return array('ok' => false, 'message' => __('Could not resolve the logo to a local file on the server.', 'estimation-tool-for-woocommerce'), 'detail' => $detail);
        }
        $detail['readable'] = file_exists($path) && is_readable($path);
        $detail['size_bytes'] = (int) @filesize($path); // phpcs:ignore
        $detail['ext'] = strtoupper(pathinfo($path, PATHINFO_EXTENSION));
        if (!$detail['readable']) {
            return array('ok' => false, 'message' => __('Logo file is not readable by PHP (check file permissions or open_basedir).', 'estimation-tool-for-woocommerce'), 'detail' => $detail);
        }
        if (!in_array($detail['ext'], array('JPG', 'JPEG', 'PNG'), true)) {
            return array('ok' => false, 'message' => sprintf(
                /* translators: %s: file extension */
                __('Unsupported logo extension: %s. Use JPG or PNG.', 'estimation-tool-for-woocommerce'),
                $detail['ext']
            ), 'detail' => $detail);
        }

        $safe = $instance->safe_image_path($path, $detail['ext']);
        $detail['safe_path'] = $safe ?: '(none)';
        if (!$safe || !file_exists($safe)) {
            // The only way this branch runs in practice: PNG-with-alpha + no GD + no Imagick.
            $has_alpha = ($detail['ext'] === 'PNG' && self::png_has_alpha($path));
            if ($has_alpha) {
                return array('ok' => false, 'message' => __(
                    'This logo is a PNG with a transparent background, and your server has neither the GD nor Imagick extension installed — TCPDF needs one of them to flatten transparency. Fix options (any one): (1) upload a JPG or a PNG without transparency, (2) enable the PHP "gd" extension (uncomment ;extension=gd in php.ini and restart Apache), or (3) install Imagick.',
                    'estimation-tool-for-woocommerce'
                ), 'detail' => $detail);
            }
            return array('ok' => false, 'message' => __('Logo file could not be prepared for embedding.', 'estimation-tool-for-woocommerce'), 'detail' => $detail);
        }
        $bytes = @file_get_contents($safe); // phpcs:ignore
        $detail['bytes_loaded'] = is_string($bytes) ? strlen($bytes) : 0;
        if (!is_string($bytes) || $bytes === '') {
            return array('ok' => false, 'message' => __('Could not read the logo bytes from disk.', 'estimation-tool-for-woocommerce'), 'detail' => $detail);
        }
        return array('ok' => true, 'message' => sprintf(
            /* translators: 1: filename 2: human size */
            __('Logo ready — %1$s (%2$s). It will appear at the top of every PDF.', 'estimation-tool-for-woocommerce'),
            basename($safe),
            size_format($detail['size_bytes'])
        ), 'detail' => $detail);
    }

    private function resolve_logo_path($logo_url) {
        // 1. attachment ID — most reliable, uses WP's resolver.
        $id = (int) Estitofo_Options::get('logo_id', 0);
        if ($id > 0 && function_exists('get_attached_file')) {
            $path = get_attached_file($id);
            if ($path) {
                $path = self::normalize_path($path);
                if ($path && file_exists($path)) {
                    return $path;
                }
            }
        }
        if ($logo_url) {
            // 2. URL → local path mapping.
            $path = $this->url_to_path($logo_url);
            if ($path) {
                $path = self::normalize_path($path);
                if ($path && file_exists($path)) {
                    return $path;
                }
            }
            // 3. WP can sometimes reverse-resolve URL → attachment.
            if (function_exists('attachment_url_to_postid')) {
                $id2 = attachment_url_to_postid($logo_url);
                if ($id2) {
                    $path = get_attached_file($id2);
                    if ($path) {
                        $path = self::normalize_path($path);
                        if ($path && file_exists($path)) {
                            return $path;
                        }
                    }
                }
                // Strip thumbnail size suffix (-150x150) and re-try.
                $stripped = preg_replace('#-\d+x\d+(\.[a-z]+)$#i', '$1', $logo_url);
                if ($stripped && $stripped !== $logo_url) {
                    $id3 = attachment_url_to_postid($stripped);
                    if ($id3) {
                        $path = get_attached_file($id3);
                        if ($path) {
                            $path = self::normalize_path($path);
                            if ($path && file_exists($path)) {
                                return $path;
                            }
                        }
                    }
                }
            }
        }
        return false;
    }

    /**
     * Normalize a filesystem path: collapse mixed slashes to the platform separator,
     * remove `..` segments, and turn it into something file_exists() reliably accepts.
     */
    private static function normalize_path($path) {
        $path = (string) $path;
        if ($path === '') return $path;
        // Pick the right separator for the platform.
        $sep = DIRECTORY_SEPARATOR;
        $norm = str_replace(array('/', '\\'), $sep, $path);
        // Collapse repeated separators (but keep Windows UNC `\\` prefix).
        if (strpos($norm, $sep . $sep) === 0) {
            $norm = $sep . $sep . preg_replace('#' . preg_quote($sep, '#') . '+#', $sep, substr($norm, 2));
        } else {
            $norm = preg_replace('#' . preg_quote($sep, '#') . '+#', $sep, $norm);
        }
        return $norm;
    }

    /**
     * Ensure an image is safe to feed TCPDF.
     *
     * - JPG: passes through.
     * - PNG without alpha: passes through (TCPDF parses these natively).
     * - PNG with alpha + GD/Imagick available: **passes through**. TCPDF's
     *   _parsepng() uses GD/Imagick to extract the alpha mask and embed it
     *   as a soft mask in the PDF — transparency is preserved end-to-end.
     * - PNG with alpha but no GD/Imagick: returns false. The diagnostic
     *   banner tells the user to upload a JPG/flat PNG or enable GD.
     */
    private function safe_image_path($path, $ext) {
        if ($ext !== 'PNG') {
            return $path;
        }
        if (!self::png_has_alpha($path)) {
            return $path;
        }
        if (extension_loaded('imagick') || function_exists('imagecreatefrompng')) {
            // Keep the original PNG so TCPDF can preserve transparency.
            return $path;
        }
        return false;
    }

    private static function png_has_alpha($path) {
        $fp = @fopen($path, 'rb'); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        if (!$fp) {
            return false;
        }
        $header = fread($fp, 26);
        fclose($fp);
        if (strlen($header) < 26 || substr($header, 1, 3) !== 'PNG') {
            return false;
        }
        $color_type = ord($header[25]);
        return ($color_type === 4 || $color_type === 6);
    }

    private static function flatten_png_to_jpg($path) {
        if (!function_exists('imagecreatefrompng') || !function_exists('imagejpeg')) {
            return false;
        }
        // Cache flattened output keyed by source path + mtime so we only do
        // the conversion once per logo/product image change.
        $upload  = wp_upload_dir();
        $tmp_dir = trailingslashit($upload['basedir']) . 'wc-estimation-tmp';
        if (!is_dir($tmp_dir)) {
            wp_mkdir_p($tmp_dir);
        }
        $key  = md5($path . '|' . (int) @filemtime($path)); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        $out  = $tmp_dir . '/flat-' . $key . '.jpg';
        if (file_exists($out)) {
            return $out;
        }

        $src = @imagecreatefrompng($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        if (!$src) {
            return false;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $dst = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $w, $h, $white);
        if (function_exists('imagealphablending')) imagealphablending($dst, true);
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
        $ok = imagejpeg($dst, $out, 88);
        imagedestroy($src);
        imagedestroy($dst);
        return $ok ? $out : false;
    }

    /**
     * Convert an uploaded-file URL (or any local upload/CDN URL that aliases
     * to a local path) to a server-side absolute path that TCPDF can read.
     *
     * Tries (in order):
     *   1. uploads basedir/baseurl
     *   2. wp-content URL → WP_CONTENT_DIR
     *   3. strips a size suffix like "-150x150" and re-tries
     *   4. attachment_url_to_postid + WP-resolved file path
     */
    private function url_to_path($url) {
        $url = (string) $url;
        if ($url === '') return false;

        // Already an absolute filesystem path?
        if ((preg_match('#^[A-Za-z]:[\\\\/]#', $url) || strpos($url, '/') === 0) && file_exists($url)) {
            return $url;
        }

        // Trim any query string (e.g. ?ver=…) and fragment.
        $clean = strtok($url, '?');
        if ($clean === false) $clean = $url;
        $clean = strtok($clean, '#');
        if ($clean === false) $clean = $url;

        $upload      = wp_upload_dir();
        $base_url    = isset($upload['baseurl']) ? $upload['baseurl'] : '';
        $base_dir    = isset($upload['basedir']) ? $upload['basedir'] : '';
        $content_url = content_url();
        $site_url    = site_url();
        $home_url    = home_url();

        // Build a list of URL prefixes that all map back to ABSPATH/wp-content.
        $candidates_url = array($clean);
        $candidates_url[] = preg_replace('#^https?://#', 'https://', $clean);
        $candidates_url[] = preg_replace('#^https?://#', 'http://', $clean);
        // Strip any leading scheme so we can also match scheme-relative or path-only inputs.
        $candidates_url = array_unique(array_filter($candidates_url));

        $maps = array(
            array($base_url,    $base_dir),
            array($content_url, defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content'),
            array($site_url,    untrailingslashit(ABSPATH)),
            array($home_url,    untrailingslashit(ABSPATH)),
        );

        foreach ($candidates_url as $candidate) {
            foreach ($maps as $map) {
                list($u, $p) = $map;
                if ($u === '' || $p === '') continue;
                if (strpos($candidate, $u) === 0) {
                    $path = $p . substr($candidate, strlen($u));
                    if (file_exists($path)) return $path;
                }
            }
        }

        // Some setups return only the size-thumb URL whose generated file may
        // not be on disk — try the source by stripping "-WIDTHxHEIGHT".
        $stripped = preg_replace('#-\d+x\d+(\.[a-z]+)$#i', '$1', $clean);
        if ($stripped && $stripped !== $clean) {
            foreach ($maps as $map) {
                list($u, $p) = $map;
                if ($u === '' || $p === '') continue;
                if (strpos($stripped, $u) === 0) {
                    $path = $p . substr($stripped, strlen($u));
                    if (file_exists($path)) return $path;
                }
            }
        }

        // Path-only URL like "/wp-content/...".
        if (strpos($clean, '/') === 0) {
            $candidate = home_url($clean);
            foreach ($maps as $map) {
                list($u, $p) = $map;
                if ($u === '' || $p === '') continue;
                if (strpos($candidate, $u) === 0) {
                    $path = $p . substr($candidate, strlen($u));
                    if (file_exists($path)) return $path;
                }
            }
        }

        // Last resort: ask WP to resolve the attachment.
        if (function_exists('attachment_url_to_postid')) {
            $id = attachment_url_to_postid($clean);
            if ($id) {
                $path = get_attached_file($id);
                if ($path && file_exists($path)) return $path;
            }
        }

        return false;
    }
}
