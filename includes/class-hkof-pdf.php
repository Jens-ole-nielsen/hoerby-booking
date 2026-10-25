<?php
if (!defined('ABSPATH')) exit;

require_once HKOF_BOOKING_DIR . 'lib/fpdf/fpdf.php';

/**
 * Genererer kontrakt-PDF (lejeaftale) og faktura-PDF som matcher
 * foreningens nuværende skabelon.
 */
class HKOF_PDF extends FPDF {

    private $logo_path = '';
    /** @var string[] Adresselinjer vist under logoet på side 1 */
    private $header_address_lines = [];
    private $show_address_on_first_page = true;
    public $margin_page1 = 44;
    public $margin_other = 32;

    public function set_logo_path($path) {
        $this->logo_path = $path;
    }

    public function set_header_address_lines(array $lines) {
        $this->header_address_lines = array_values(array_filter(array_map('strval', $lines)));
    }

    public function set_show_address_on_first_page($show) {
        $this->show_address_on_first_page = (bool) $show;
    }

    /** Konverterer UTF-8 til CP1252/ISO-8859-1 så æøå vises korrekt med FPDF's kernefonte */
    public static function enc($text) {
        $converted = @iconv('UTF-8', 'CP1252//IGNORE', $text);
        return $converted !== false ? $converted : $text;
    }

    /** Beregn top-margener ud fra logo-højde, så brødtekst ikke overlapper header */
    public function configure_header_margins() {
        $logo_h = 20;
        if ($this->logo_path && file_exists($this->logo_path)) {
            $info = @getimagesize($this->logo_path);
            if ($info && !empty($info[0])) {
                $logo_h = 50 * ($info[1] / $info[0]);
            }
        }
        $this->margin_other = (int) max(30, ceil(8 + $logo_h + 8));
        $address_h = $this->show_address_on_first_page ? (count($this->header_address_lines) * 4 + 4) : 0;
        // Ekstra luft under logo/adresse, så brødteksten ikke starter for højt
        $this->margin_page1 = (int) max($this->margin_other, ceil(8 + $logo_h + 2 + $address_h + 12));
    }

    /**
     * Logo øverst til højre. På side 1 vises adresse under logoet.
     * Opdaterer tMargin pr. side – FPDF sætter Y = tMargin efter Header(),
     * så en SetY() her alene ville blive overskrevet (det var årsagen til
     * overlap på side 2).
     */
    public function Header() {
        $top = 8;
        $logo_w = 50;
        $page_w = $this->GetPageWidth();
        $logo_x = $page_w - $this->rMargin - $logo_w;
        $logo_h = 0;

        if ($this->logo_path && file_exists($this->logo_path)) {
            $info = @getimagesize($this->logo_path);
            if ($info && !empty($info[0])) {
                $logo_h = $logo_w * ($info[1] / $info[0]);
            } else {
                $logo_h = 20;
            }
            $this->Image($this->logo_path, $logo_x, $top, $logo_w);
        }

        if ($this->show_address_on_first_page && $this->PageNo() === 1 && $this->header_address_lines) {
            $y = $top + ($logo_h > 0 ? $logo_h + 2 : 0);
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(70, 70, 70);
            foreach ($this->header_address_lines as $line) {
                $this->SetXY($logo_x, $y);
                $this->Cell($logo_w, 4, self::enc($line), 0, 0, 'R');
                $y += 4;
            }
            $this->SetTextColor(0, 0, 0);
        }

        $margin = ($this->PageNo() === 1) ? $this->margin_page1 : $this->margin_other;
        $this->SetMargins($this->lMargin, $margin, $this->rMargin);
        // Vigtigt: adresselinjerne ovenfor efterlader X ude til højre. Denne FPDF-version
        // nulstiller ikke X/Y efter Header(), så uden dette skrives "LEJEAFTALE" i en
        // smal spalte under logoet og brydes midt i ordet.
        $this->SetXY($this->lMargin, $this->tMargin);
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 10, self::enc('Side ' . $this->PageNo() . ' / {nb}'), 0, 0, 'C');
    }

    /**
     * Bygger den fulde kontrakt-PDF for en booking og returnerer filstien.
     */
    public static function generate_contract($booking) {
        $s = HKOF_Settings::all();
        $pdf = new self();
        $pdf->AliasNbPages();

        $logo_path = '';
        if ($s['logo_id']) {
            $path = get_attached_file($s['logo_id']);
            if ($path && file_exists($path)) $logo_path = $path;
        }
        $pdf->set_logo_path($logo_path);
        $pdf->set_header_address_lines([
            $s['association_address'],
            $s['association_postal'],
            'Hjemmeside: ' . $s['association_website'],
        ]);
        $pdf->set_show_address_on_first_page(true);
        $pdf->configure_header_margins();
        $pdf->SetMargins(15, $pdf->margin_page1, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        // Foreningsnavn i venstre side fjernes når logo bruges. Uden logo
        // vises navnet stadig, så dokumentet ikke mangler afsender.
        if (!$logo_path) {
            $pdf->SetFont('Arial', 'B', 14);
            $pdf->Cell(0, 8, self::enc($s['association_name']), 0, 1);
            $pdf->Ln(2);
        }

        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, self::enc('LEJEAFTALE'), 0, 1, 'L');
        $pdf->Ln(8);

        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6, self::enc('Lejeaftale nr. ' . $booking->booking_ref), 0, 1);
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 6, self::enc('Den ' . date_i18n('d.m.Y')), 0, 1);
        $pdf->Ln(6);

        $pdf->SetFont('Arial', '', 11);
        $pdf->MultiCell(0, 6, self::enc(HKOF_Settings::contract_text('intro_text')));
        $pdf->Ln(2);

        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6, self::enc($booking->first_name . ' ' . $booking->last_name), 0, 1);
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 6, self::enc($booking->address), 0, 1);
        $pdf->Cell(0, 6, self::enc($booking->postal_city), 0, 1);
        $pdf->Cell(0, 6, self::enc($booking->email), 0, 1);
        $pdf->Cell(0, 6, self::enc($booking->phone), 0, 1);
        $pdf->Ln(4);

        $check_in  = date_i18n('d.m.Y', strtotime($booking->check_in_date));
        $check_out = date_i18n('d.m.Y', strtotime($booking->check_out_date));
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6, self::enc('Lejeperioden:'), 0, 1);
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 6, self::enc("Ankomst $check_in kl. 12.00 til den $check_out kl. 12.00"), 0, 1);
        $pdf->Ln(4);

        $deposit_limit = date_i18n('d.m.Y', strtotime('+' . (int) $s['deposit_days_limit'] . ' days'));
        $extra_days = (int) $booking->extra_days;
        $extra_fee = (float) $booking->extra_days_fee;
        $rental_total = (float) $booking->rental_amount + $extra_fee + (float) $booking->environment_fee;

        $pdf->MultiCell(0, 6, self::enc(sprintf(
            'Depositum udgør kr. %s og bedes indbetalt indenfor %d dage.',
            number_format((float) $booking->deposit_amount, 2, ',', '.'), (int) $s['deposit_days_limit']
        )));
        if ($extra_days > 0) {
            $pdf->MultiCell(0, 6, self::enc(sprintf(
                'Lejeafgiften udgør kr. %s, tillagt %d ekstra dag(e) á kr. %s = kr. %s, + miljøafgift kr. %s (Incl. Moms). I alt kr. %s. Indbetales senest %d dage før arrangementet afholdes.',
                number_format((float) $booking->rental_amount, 2, ',', '.'),
                $extra_days,
                number_format((float) $s['price_extra_day'], 2, ',', '.'),
                number_format($extra_fee, 2, ',', '.'),
                number_format((float) $booking->environment_fee, 2, ',', '.'),
                number_format($rental_total, 2, ',', '.'),
                (int) $s['invoice_days_before']
            )));
        } else {
            $pdf->MultiCell(0, 6, self::enc(sprintf(
                'Lejeafgiften udgør kr. %s, + miljøafgift kr. %s (Incl. Moms). Indbetales senest %d dage før arrangementet afholdes.',
                number_format((float) $booking->rental_amount, 2, ',', '.'),
                number_format((float) $booking->environment_fee, 2, ',', '.'),
                (int) $s['invoice_days_before']
            )));
        }
        $pdf->Ln(2);

        $pdf->MultiCell(0, 6, self::enc(sprintf(
            'Depositum og lejeafgift indbetales til %s Reg. Nr. %s Konto nr. %s, senest på de anførte datoer.',
            $s['bank_navn'], $s['bank_reg'], $s['bank_konto']
        )));
        $pdf->Ln(2);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->MultiCell(0, 6, self::enc('VIGTIGT !! - Husk at påføre lejeaftale nr. ' . $booking->booking_ref . ' på din indbetaling'));
        $pdf->Ln(3);

        $pdf->SetFont('Arial', '', 10);
        $terms = array_values(array_filter(array_map('trim', explode("\n\n", HKOF_Settings::contract_text('terms_general')))));
        foreach ($terms as $t) { $pdf->MultiCell(0, 5.5, self::enc($t)); $pdf->Ln(1.5); }

        $pdf->Ln(2);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->MultiCell(0, 6, self::enc('Lejeaftalen bekræftes ved indbetaling af depositum kr. ' . number_format((float) $booking->deposit_amount, 0, ',', '.') . '.'));
        $pdf->MultiCell(0, 6, self::enc('Lejeaftalen er først gyldig når depositum og kontonr. er modtaget.'));
        $pdf->Ln(4);

        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 6, self::enc('Udlejning              ' . $s['udlejning_navn'] . ', ' . $s['udlejning_tlf']), 0, 1);
        $pdf->Cell(0, 6, self::enc('Tilsynsførende     ' . $s['tilsyn_navn'] . ', ' . $s['tilsyn_adresse'] . ' Tlf. ' . $s['tilsyn_tlf']), 0, 1);
        $pdf->Ln(6);

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 6, self::enc('Nøgler:'), 0, 1);
        $pdf->SetFont('Arial', '', 10);
        foreach (array_values(array_filter(array_map('trim', explode("\n\n", HKOF_Settings::contract_text('keys_text'))))) as $line) {
            $pdf->MultiCell(0, 5.5, self::enc($line));
        }
        $pdf->Ln(4);

        foreach (array_values(array_filter(array_map('trim', explode("\n\n", HKOF_Settings::contract_text('selvbetjening_text'))))) as $line) {
            $pdf->MultiCell(0, 5.5, self::enc($line));
            $pdf->Ln(1);
        }

        // Opvask/rengøring/ordensregler fortsætter i naturligt flow (ingen tvunget sideskift
        // her længere) - FPDF bryder selv siden når der ikke er mere plads.
        $pdf->Ln(4);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 7, self::enc('Opvask:'), 0, 1);
        $pdf->SetFont('Arial', '', 10);
        foreach (array_values(array_filter(array_map('trim', explode("\n\n", HKOF_Settings::contract_text('opvask_text'))))) as $line) {
            $pdf->MultiCell(0, 5.5, self::enc($line));
        }
        $pdf->Ln(3);

        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 7, self::enc('Rengøring:'), 0, 1);
        $pdf->SetFont('Arial', '', 10);
        $pdf->MultiCell(0, 5.5, self::enc(HKOF_Settings::contract_text('rengoring_intro')));
        $rengoring = array_values(array_filter(array_map('trim', explode("\n", HKOF_Settings::contract_text('rengoring_liste')))));
        foreach ($rengoring as $i => $r) $pdf->MultiCell(0, 5.5, self::enc(($i + 1) . '. ' . $r));
        $pdf->Ln(2);
        $pdf->MultiCell(0, 5.5, self::enc(HKOF_Settings::contract_text('rengoring_outro')));
        $pdf->Ln(3);

        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 7, self::enc('Ordensregler:'), 0, 1);
        $pdf->SetFont('Arial', '', 10);
        $ordensregler = array_values(array_filter(array_map('trim', explode("\n", HKOF_Settings::contract_text('ordensregler')))));
        foreach ($ordensregler as $o) { $pdf->MultiCell(0, 5.5, self::enc($o)); $pdf->Ln(1.5); }

        $filepath = self::target_pdf_path('contract', $booking);
        $pdf->Output('F', $filepath);
        self::remove_legacy_pdf('contract', $booking, $filepath);
        return $filepath;
    }

    /**
     * Genererer faktura-PDF (lejeafgift + miljøafgift) for en booking.
     */
    public static function generate_invoice($booking) {
        $s = HKOF_Settings::all();
        $pdf = new self();
        $pdf->AliasNbPages();

        $logo_path = '';
        if ($s['logo_id']) {
            $path = get_attached_file($s['logo_id']);
            if ($path && file_exists($path)) $logo_path = $path;
        }
        $pdf->set_logo_path($logo_path);
        $pdf->set_header_address_lines([
            $s['association_address'],
            $s['association_postal'],
            'CVR: ' . $s['cvr'],
        ]);
        $pdf->set_show_address_on_first_page(true);
        $pdf->configure_header_margins();
        $pdf->SetMargins(15, $pdf->margin_page1, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        if (!$logo_path) {
            $pdf->SetFont('Arial', 'B', 14);
            $pdf->Cell(0, 8, self::enc($s['association_name']), 0, 1);
            $pdf->Ln(2);
        }

        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, self::enc('FAKTURA'), 0, 1, 'L');
        $pdf->Ln(8);

        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 6, self::enc('Fakturanr.: ' . $booking->booking_ref), 0, 1);
        $pdf->Cell(0, 6, self::enc('Dato: ' . date_i18n('d.m.Y')), 0, 1);
        $check_in  = date_i18n('d.m.Y', strtotime($booking->check_in_date));
        $check_out = date_i18n('d.m.Y', strtotime($booking->check_out_date));
        $pdf->Cell(0, 6, self::enc("Vedr. leje af huset: $check_in - $check_out"), 0, 1);
        $pdf->Ln(4);

        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6, self::enc($booking->first_name . ' ' . $booking->last_name), 0, 1);
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 6, self::enc($booking->address), 0, 1);
        $pdf->Cell(0, 6, self::enc($booking->postal_city), 0, 1);
        $pdf->Ln(8);

        // Linjetabel
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetFillColor(235, 235, 235);
        $pdf->Cell(120, 8, self::enc('Beskrivelse'), 1, 0, 'L', true);
        $pdf->Cell(60, 8, self::enc('Beløb (kr.)'), 1, 1, 'R', true);

        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(120, 8, self::enc('Lejeafgift'), 1, 0, 'L');
        $pdf->Cell(60, 8, number_format((float) $booking->rental_amount, 2, ',', '.'), 1, 1, 'R');
        if ((int) $booking->extra_days > 0) {
            $pdf->Cell(120, 8, self::enc((int) $booking->extra_days . ' ekstra dag(e)'), 1, 0, 'L');
            $pdf->Cell(60, 8, number_format((float) $booking->extra_days_fee, 2, ',', '.'), 1, 1, 'R');
        }
        $pdf->Cell(120, 8, self::enc('Miljøafgift'), 1, 0, 'L');
        $pdf->Cell(60, 8, number_format((float) $booking->environment_fee, 2, ',', '.'), 1, 1, 'R');

        $total = (float) $booking->rental_amount + (float) $booking->extra_days_fee + (float) $booking->environment_fee;
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(120, 8, self::enc('I alt (inkl. moms)'), 1, 0, 'L');
        $pdf->Cell(60, 8, number_format($total, 2, ',', '.'), 1, 1, 'R');
        $pdf->Ln(6);

        $pdf->SetFont('Arial', '', 10);
        $pdf->MultiCell(0, 6, self::enc(sprintf(
            'Beløbet indbetales til %s Reg. Nr. %s Konto nr. %s senest %d dage før arrangementet afholdes.',
            $s['bank_navn'], $s['bank_reg'], $s['bank_konto'], (int) $s['invoice_days_before']
        )));
        $pdf->Ln(2);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->MultiCell(0, 6, self::enc('VIGTIGT !! - Husk at påføre lejeaftale nr. ' . $booking->booking_ref . ' på din indbetaling.'));

        $filepath = self::target_pdf_path('invoice', $booking);
        $pdf->Output('F', $filepath);
        self::remove_legacy_pdf('invoice', $booking, $filepath);
        return $filepath;
    }

    /**
     * Sikrer at PDF-mappen findes og ikke kan listes/hentes direkte via HTTP
     * (Apache .htaccess). Nginx ignorerer .htaccess, derfor har filerne også
     * et ugætteligt navn, og visning sker kun via det login-beskyttede download-endpoint.
     */
    public static function ensure_contracts_dir() {
        $upload_dir = wp_upload_dir();
        $dir = trailingslashit($upload_dir['basedir']) . 'hkof-contracts/';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        $htaccess = $dir . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess,
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
                . "Options -Indexes\n"
            );
        }
        $index = $dir . 'index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
        return $dir;
    }

    public static function download_url($booking_id, $type) {
        $type = $type === 'invoice' ? 'invoice' : 'contract';
        $id = (int) $booking_id;
        return wp_nonce_url(
            admin_url('admin-post.php?action=hkof_download_pdf&booking_id=' . $id . '&type=' . $type),
            'hkof_download_pdf_' . $id . '_' . $type
        );
    }

    public static function handle_download() {
        $id = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0;
        $type = (isset($_GET['type']) && $_GET['type'] === 'invoice') ? 'invoice' : 'contract';
        check_admin_referer('hkof_download_pdf_' . $id . '_' . $type);
        if (!current_user_can('edit_posts') || !$id) {
            wp_die('Ingen adgang');
        }
        $booking = HKOF_DB::get($id);
        if (!$booking || empty($booking->booking_ref)) {
            wp_die('PDF ikke fundet');
        }
        $path = self::find_existing_pdf($type, $booking);
        if (!$path) {
            wp_die('PDF ikke fundet');
        }
        $download_name = ($type === 'invoice' ? 'faktura-' : 'lejeaftale-') . preg_replace('/[^A-Za-z0-9._-]/', '', $booking->booking_ref) . '.pdf';
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $download_name . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    /** Sti til eksisterende PDF, eller tom streng. Flytter ældre forudsigelige filnavne til hashed navn. */
    public static function find_existing_pdf($type, $booking) {
        $target = self::target_pdf_path($type, $booking);
        if ($target && file_exists($target)) {
            return $target;
        }
        $legacy = self::legacy_pdf_path($type, $booking);
        if ($legacy && file_exists($legacy)) {
            if (@rename($legacy, $target)) {
                return $target;
            }
            return $legacy;
        }
        return '';
    }

    public static function target_pdf_path($type, $booking) {
        if (empty($booking->booking_ref)) {
            return '';
        }
        $dir = self::ensure_contracts_dir();
        $prefix = $type === 'invoice' ? 'faktura' : 'lejeaftale';
        $ref = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $booking->booking_ref);
        $token = substr(hash_hmac('sha256', $ref, wp_salt('auth')), 0, 16);
        return $dir . $prefix . '-' . $ref . '-' . $token . '.pdf';
    }

    private static function legacy_pdf_path($type, $booking) {
        if (empty($booking->booking_ref)) {
            return '';
        }
        $dir = self::ensure_contracts_dir();
        $prefix = $type === 'invoice' ? 'faktura' : 'lejeaftale';
        $ref = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $booking->booking_ref);
        return $dir . $prefix . '-' . $ref . '.pdf';
    }

    private static function remove_legacy_pdf($type, $booking, $keep_path) {
        $legacy = self::legacy_pdf_path($type, $booking);
        if ($legacy && $legacy !== $keep_path && file_exists($legacy)) {
            @unlink($legacy);
        }
    }
}
