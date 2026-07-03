<?php
if (!defined('ABSPATH')) exit;

require_once HKOF_BOOKING_DIR . 'lib/fpdf/fpdf.php';

/**
 * Genererer kontrakt-PDF (lejeaftale) og faktura-PDF som matcher
 * foreningens nuværende skabelon.
 */
class HKOF_PDF extends FPDF {

    private $logo_path = '';

    public function set_logo_path($path) {
        $this->logo_path = $path;
    }

    /** Konverterer UTF-8 til CP1252/ISO-8859-1 så æøå vises korrekt med FPDF's kernefonte */
    public static function enc($text) {
        $converted = @iconv('UTF-8', 'CP1252//IGNORE', $text);
        return $converted !== false ? $converted : $text;
    }

    public function Header() {
        if ($this->logo_path && file_exists($this->logo_path)) {
            $this->Image($this->logo_path, 145, 8, 55);
        }
        // Fra side 2 og frem er der intet fast sidehoved med foreningsnavn (det ligger kun
        // på side 1), så uden dette ville brødteksten begynde helt oppe i logoets område og
        // kollidere med det. Vi rykker derfor startpunktet for teksten et stykke ned.
        if ($this->PageNo() > 1) {
            $this->SetY(26);
        }
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

        $pdf->AddPage();
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(120, 8, self::enc($s['association_name']), 0, 1);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(120, 5, self::enc($s['association_address']), 0, 1);
        $pdf->Cell(120, 5, self::enc($s['association_postal']), 0, 1);
        $pdf->Cell(120, 5, self::enc('Hjemmeside: ' . $s['association_website']), 0, 1);
        $pdf->Ln(8);

        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, self::enc('LEJEAFTALE'), 0, 1, 'C');
        $pdf->Ln(2);

        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6, self::enc('Lejeaftale nr. ' . $booking->booking_ref), 0, 1);
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 6, self::enc('Den ' . date_i18n('d.m.Y')), 0, 1);
        $pdf->Ln(4);

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

        $upload_dir = wp_upload_dir();
        $dir = trailingslashit($upload_dir['basedir']) . 'hkof-contracts/';
        if (!file_exists($dir)) wp_mkdir_p($dir);
        $filename = 'lejeaftale-' . $booking->booking_ref . '.pdf';
        $filepath = $dir . $filename;
        $pdf->Output('F', $filepath);
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

        $pdf->AddPage();
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(120, 8, self::enc($s['association_name']), 0, 1);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(120, 5, self::enc($s['association_address']), 0, 1);
        $pdf->Cell(120, 5, self::enc($s['association_postal']), 0, 1);
        $pdf->Cell(120, 5, self::enc('CVR: ' . $s['cvr']), 0, 1);
        $pdf->Ln(8);

        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, self::enc('FAKTURA'), 0, 1, 'C');
        $pdf->Ln(2);

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

        $upload_dir = wp_upload_dir();
        $dir = trailingslashit($upload_dir['basedir']) . 'hkof-contracts/';
        if (!file_exists($dir)) wp_mkdir_p($dir);
        $filename = 'faktura-' . $booking->booking_ref . '.pdf';
        $filepath = $dir . $filename;
        $pdf->Output('F', $filepath);
        return $filepath;
    }
}
