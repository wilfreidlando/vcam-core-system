<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Documents;

use Core\Billing\Contracts\InvoiceLineView;
use Core\Billing\Contracts\InvoiceView;
use Core\Support\Money\Money;

/**
 * ADR-0073 §5 — the printable invoice.
 *
 * The document is rendered from the **frozen lines**: what is printed is
 * what was issued, never what the catalogue says today (ADR-0073
 * §Alternatives).
 *
 * The customer frozen on the invoice is shown by its **name** — it is
 * the document the customer receives, and a legal document that does not
 * name its debtor is worthless. The name was **encrypted** at rest
 * (ADR-0083 §2) and is decrypted here, at the moment of printing, and
 * nowhere else: the API never returns it, and the console shows only the
 * project's own reference.
 *
 * Everything the caller can write is escaped: a line's description is
 * free text, and a printable document is a page a browser will render.
 */
final readonly class InvoiceDocumentRenderer
{
    /**
     * @param array<string, mixed> $branding the free marks of the project
     *                                       (ADR-0094 §4)
     */
    public function render(InvoiceView $invoice, array $branding = []): string
    {
        $company = $this->text($branding['company_name'] ?? 'Facturation');
        $mentions = $this->text($branding['mentions'] ?? '');

        $rows = '';
        foreach ($invoice->lines as $line) {
            $rows .= $this->row($line, $invoice->currency);
        }

        $paid = '';
        if ($invoice->amountPaidMinor > 0) {
            $paid = sprintf(
                '<p class="paid">Déjà réglé : %s %s</p>',
                $this->money($invoice->amountPaidMinor, $invoice->currency),
                $this->escape($invoice->currency),
            );
        }

        $void = '';
        if ($invoice->status === 'void') {
            $void = sprintf(
                '<p class="void">Facture annulée%s</p>',
                $invoice->voidReason === null
                    ? ''
                    : ' — '.$this->escape($invoice->voidReason),
            );
        }

        return <<<HTML
        <!doctype html>
        <html lang="fr">
        <head>
        <meta charset="utf-8">
        <title>Facture {$this->escape($invoice->number ?? 'brouillon')}</title>
        <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; color: #0f172a; margin: 2.5rem; }
        h1 { font-size: 1.4rem; margin: 0 0 .25rem; }
        .meta { color: #475569; font-size: .9rem; margin-bottom: 1.5rem; }
        table { border-collapse: collapse; width: 100%; margin: 1rem 0; }
        th, td { border-bottom: 1px solid #e2e8f0; padding: .5rem .4rem; text-align: left; font-size: .92rem; }
        th.num, td.num { text-align: right; }
        tfoot td { font-weight: 600; border-bottom: none; }
        .paid { color: #15803d; font-size: .9rem; }
        .void { color: #b91c1c; font-weight: 600; }
        .mentions { margin-top: 2rem; color: #475569; font-size: .85rem; white-space: pre-line; }
        @media print { body { margin: 1rem; } }
        </style>
        </head>
        <body>
        <h1>{$company}</h1>
        <p class="meta">
        Facture {$this->escape($invoice->number ?? 'brouillon')}<br>
        Client : {$this->escape($this->customerName($invoice))}<br>
        Client de référence : {$this->escape($invoice->customerRef ?? '')}<br>
        Émise le {$this->escape($invoice->issuedAt ?? '—')}<br>
        Échéance : {$this->escape($invoice->dueAt ?? 'à réception')}
        </p>
        <table>
        <thead>
        <tr>
        <th>Désignation</th>
        <th class="num">Qté</th>
        <th class="num">Prix unitaire</th>
        <th class="num">Montant</th>
        <th class="num">Taxe</th>
        </tr>
        </thead>
        <tbody>
        {$rows}
        </tbody>
        <tfoot>
        <tr><td colspan="3">Sous-total</td><td class="num">{$this->money($invoice->subtotalMinor, $invoice->currency)}</td><td></td></tr>
        <tr><td colspan="3">Taxe</td><td></td><td class="num">{$this->money($invoice->taxMinor, $invoice->currency)}</td></tr>
        <tr><td colspan="3">Total</td><td class="num">{$this->money($invoice->totalMinor, $invoice->currency)}</td><td></td></tr>
        </tfoot>
        </table>
        {$paid}
        {$void}
        <p class="mentions">{$mentions}</p>
        </body>
        </html>
        HTML;
    }

    private function row(InvoiceLineView $line, string $currency): string
    {
        return sprintf(
            '<tr><td>%s</td><td class="num">%d</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td></tr>',
            $this->escape($line->description),
            $line->quantity,
            $this->money($line->unitAmountMinor, $currency),
            $this->money($line->amountMinor, $currency),
            $this->money($line->taxAmountMinor, $currency),
        );
    }

    /**
     * The name frozen on the invoice, read for the printing — and only
     * for it (ADR-0094 §2, ADR-0123).
     */
    private function customerName(InvoiceView $invoice): string
    {
        $name = $invoice->customerSnapshot['display_name'] ?? null;

        if (! is_string($name) || $name === '') {
            return $invoice->customerRef ?? '';
        }

        return $name;
    }

    /**
     * The amount, formatted by the shared kernel — never a float, never
     * a locale of its own (G5, ADR-0021).
     */
    private function money(int $amountMinor, string $currency): string
    {
        return $this->escape(Money::ofMinor($amountMinor, $currency)->formattedAmount());
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $this->escape($value) : '';
    }
}
