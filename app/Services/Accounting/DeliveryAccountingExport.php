<?php

namespace App\Services\Accounting;

use App\Models\User;
use Carbon\CarbonPeriod;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeliveryAccountingExport
{
    public function download(string $from, string $to, User $actor): StreamedResponse
    {
        $data = app(DeliveryAccounting::class)->data($from, $to, $actor);

        return response()->streamDownload(function () use ($data) {
            $book = $this->spreadsheet($data);
            try {
                (new Xlsx($book))->save('php://output');
            } finally {
                $book->disconnectWorksheets();
            }
        }, 'iapi-accounting-'.$from.'_'.$to.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function spreadsheet(array $data): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('IAPI.GE')->setTitle(__('accounting.title'));
        $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);
        $sheet = $book->getActiveSheet()->setTitle(__('accounting.sheets.orders'));
        foreach (['A' => 22, 'B' => 18, 'C' => 18, 'D' => 18] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->setShowGridlines(false);
        $sheet->freezePane('A4');
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageMargins()->setLeft(0.3)->setRight(0.3)->setTop(0.4)->setBottom(0.4);
        $this->heading($sheet, 1, 'IAPI.GE | '.__('accounting.export.vertical_title'), 'FF283046', 16);
        $this->mergedText($sheet, 'A2:D2', $data['from'].' — '.$data['to']);
        $this->mergedText($sheet, 'A3:D3', __('accounting.export.manual_help'));
        $sheet->getStyle('A3:D3')->getFont()->setSize(10)->getColor()->setARGB('FF7A5A00');
        $sheet->getStyle('A3:D3')->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(3)->setRowHeight(32);
        $row = 5;
        $ordersByDate = $data['orders']->groupBy('delivery_date');
        $period = ['sales' => [], 'cost' => [], 'expenses' => [], 'profit' => []];
        foreach (CarbonPeriod::create($data['from'], $data['to']) as $date) {
            $key = $date->toDateString();
            $this->heading($sheet, $row++, __('accounting.export.day_title', ['date' => $date->format('d.m.Y')]), 'FFFF7900', 13);
            $daySales = [];
            $dayCosts = [];
            foreach ($ordersByDate->get($key, collect())->values() as $index => $order) {
                $eligible = in_array($order['status'], ['active', 'delivered'], true);
                $this->heading($sheet, $row++, __('accounting.export.order_title', ['number' => $index + 1, 'order' => $order['order_number']]).' · '.__('delivery.'.$order['status']), $eligible ? 'FFE8EDF4' : 'FFF1E6F8', 11, 'FF283046');
                $this->detail($sheet, $row++, __('accounting.export.customer'), $order['customer_name']);
                $this->detail($sheet, $row++, __('accounting.export.phone'), $order['phone']);
                if (! $eligible) {
                    $this->mergedText($sheet, 'A'.$row.':D'.$row, __('accounting.export.excluded'));
                    $sheet->getStyle('A'.$row.':D'.$row)->getFont()->setSize(10)->getColor()->setARGB('FF8A549D');
                    $sheet->getRowDimension($row++)->setRowHeight(26);
                }
                $lines = $order['lines']->isEmpty() ? collect([null]) : $order['lines'];
                if ($lines->count() === 1) {
                    [$sale, $cost, $profit] = $this->singleOrder($book, $sheet, $order, $lines->first(), $row);
                } else {
                    $costCells = [];
                    $lines = $order['lines']->isEmpty() ? collect([null]) : $order['lines'];
                    foreach ($lines as $line) {
                        $this->mergedText($sheet, 'A'.$row.':D'.$row, $line['name'] ?? __('accounting.export.item_missing'));
                        $sheet->getStyle('A'.$row.':D'.$row)->getFont()->setBold(true);
                        $sheet->getStyle('A'.$row.':D'.$row)->getAlignment()->setWrapText(true);
                        $sheet->getRowDimension($row++)->setRowHeight(34);
                        $this->mergedText($sheet, 'A'.$row.':D'.$row, trim(($line['supplier_name'] ?? '').' · '.($line['sku'] ?? ''), ' ·'));
                        $sheet->getStyle('A'.$row.':D'.$row)->getFont()->setSize(9)->getColor()->setARGB('FF667085');
                        $sheet->getRowDimension($row++)->setRowHeight(18);
                        $quantity = $line['qty'] ?? ($order['product_revenue_cents'] > 0 ? 1 : 0);
                        $this->text($sheet, 'A'.$row, __('accounting.export.quantity'));
                        $sheet->setCellValueExplicit('B'.$row, $quantity, DataType::TYPE_NUMERIC);
                        $this->text($sheet, 'C'.$row, __('accounting.export.unit_sale'));
                        $this->money($sheet, 'D'.$row, $line['unit_sale_cents'] ?? $order['product_revenue_cents']);
                        $quantityCell = 'B'.$row++;
                        $saleCell = 'D'.$row;
                        $this->moneyRow($sheet, $row++, __('accounting.export.line_sale'), $line['sale_cents'] ?? $order['product_revenue_cents']);
                        $unitCostCell = 'D'.$row;
                        $this->label($sheet, $row, __('accounting.export.purchase_unit'));
                        $this->input($sheet, $unitCostCell);
                        $sheet->getRowDimension($row++)->setRowHeight(28);
                        $costCell = 'D'.$row;
                        $this->formulaRow($sheet, $row++, __('accounting.export.total_cost'), '=IF('.$quantityCell.'=0,0,IF(AND(ISNUMBER('.$unitCostCell.'),'.$unitCostCell.'>=0),ROUND('.$quantityCell.'*'.$unitCostCell.',2),""))');
                        $marginCell = 'D'.$row;
                        $this->formulaRow($sheet, $row++, __('accounting.export.line_margin'), '=IF(COUNT('.$costCell.')=1,ROUND('.$saleCell.'-'.$costCell.',2),"")');
                        if ($line !== null) {
                            $this->name($book, $sheet, 'ITEM_'.$line['item_id'].'_UNIT_COST', $unitCostCell);
                            $this->name($book, $sheet, 'ITEM_'.$line['item_id'].'_COST', $costCell);
                            $this->name($book, $sheet, 'ITEM_'.$line['item_id'].'_PROFIT', $marginCell);
                        }
                        $costCells[] = $costCell;
                        $row++;
                    }
                    $sale = 'D'.$row;
                    $this->moneyRow($sheet, $row++, __('accounting.export.order_sale_total'), $order['total_cents'], true);
                    $this->moneyRow($sheet, $row++, __('accounting.export.shipping_once'), $order['shipping_cents']);
                    $cost = 'D'.$row;
                    $this->formulaRow($sheet, $row++, __('accounting.export.order_cost_total'), $this->completeSum($costCells), true);
                    $profit = 'D'.$row;
                    $this->formulaRow($sheet, $row++, __('accounting.export.order_profit'), '=IF(COUNT('.$cost.')=1,ROUND('.$sale.'-'.$cost.',2),"")', true);
                }
                foreach (['SALE' => $sale, 'COST' => $cost, 'PROFIT' => $profit] as $suffix => $cell) {
                    $this->name($book, $sheet, 'ORDER_'.$order['delivery_id'].'_'.$suffix, $cell);
                }
                if ($eligible) {
                    $daySales[] = $sale;
                    $dayCosts[] = $cost;
                }
                $row += 2;
            }
            $this->heading($sheet, $row++, __('accounting.export.day_summary', ['date' => $date->format('d.m.Y')]), 'FF283046', 12);
            $day = [];
            $day['sales'] = 'D'.$row;
            $this->formulaRow($sheet, $row++, __('accounting.export.sales_total'), $this->sum($daySales), true);
            $day['cost'] = 'D'.$row;
            $this->formulaRow($sheet, $row++, __('accounting.export.purchase_total'), $this->completeSum($dayCosts, true), true);
            $this->formulaRow($sheet, $row++, __('accounting.export.gross_profit'), '=IF(COUNT('.$day['cost'].')=1,ROUND('.$day['sales'].'-'.$day['cost'].',2),"")');
            $expenseCells = [];
            foreach (['DRIVER' => 'driver_fuel', 'BOOST' => 'boost', 'OTHER' => 'other'] as $suffix => $label) {
                $cell = 'D'.$row;
                $this->label($sheet, $row, __('accounting.export.'.$label));
                $this->input($sheet, $cell);
                $sheet->getRowDimension($row++)->setRowHeight(26);
                $expenseCells[] = $cell;
                $this->name($book, $sheet, 'DAY_'.$date->format('Ymd').'_'.$suffix, $cell);
            }
            $day['expenses'] = 'D'.$row;
            $refs = implode(',', $expenseCells);
            $this->formulaRow($sheet, $row++, __('accounting.export.total_expenses'), '=IF(OR(COUNT('.$refs.')<>COUNTA('.$refs.'),MIN('.$refs.')<0),"",SUM('.$refs.'))', true);
            $day['profit'] = 'D'.$row;
            $this->formulaRow($sheet, $row++, __('accounting.export.final_profit'), '=IF(COUNT('.$day['cost'].','.$day['expenses'].')=2,ROUND('.$day['sales'].'-'.$day['cost'].'-'.$day['expenses'].',2),"")', true);
            $sheet->getStyle('A'.($row - 1).':D'.($row - 1))->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE4F5E9');
            foreach ($day as $suffix => $cell) {
                $period[$suffix][] = $cell;
                $this->name($book, $sheet, 'DAY_'.$date->format('Ymd').'_'.strtoupper($suffix), $cell);
            }
            $row += 2;
        }
        if (count($period['sales']) > 1) {
            $this->heading($sheet, $row++, __('accounting.export.period_total'), 'FF283046', 12);
            foreach (['sales' => 'sales_total', 'cost' => 'purchase_total', 'expenses' => 'total_expenses', 'profit' => 'final_profit'] as $key => $label) {
                $cell = 'D'.$row;
                $formula = $key === 'sales' ? $this->sum($period[$key]) : $this->completeSum($period[$key]);
                $this->formulaRow($sheet, $row++, __('accounting.export.'.$label), $formula, true);
                $this->name($book, $sheet, 'PERIOD_'.strtoupper($key), $cell);
            }
        } else {
            foreach ($period as $key => $cells) {
                $this->name($book, $sheet, 'PERIOD_'.strtoupper($key), $cells[0]);
            }
        }
        $sheet->getStyle('A1:D'.($row - 1))->getAlignment()->setVertical('center');
        $sheet->getPageSetup()->setPrintArea('A1:D'.($row - 1));
        $book->setActiveSheetIndex(0);

        return $book;
    }

    protected function singleOrder(Spreadsheet $book, Worksheet $sheet, array $order, ?array $line, int &$row): array
    {
        $this->mergedText($sheet, 'A'.$row.':D'.$row, $line['name'] ?? __('accounting.export.item_missing'));
        $sheet->getStyle('A'.$row.':D'.$row)->getFont()->setBold(true);
        $sheet->getStyle('A'.$row.':D'.$row)->getAlignment()->setWrapText(true);
        $sheet->getRowDimension($row++)->setRowHeight(34);
        $this->mergedText($sheet, 'A'.$row.':D'.$row, trim(($line['supplier_name'] ?? '').' · '.($line['sku'] ?? ''), ' ·'));
        $sheet->getStyle('A'.$row.':D'.$row)->getFont()->setSize(9)->getColor()->setARGB('FF667085');
        $sheet->getRowDimension($row++)->setRowHeight(18);
        $quantity = $line['qty'] ?? ($order['product_revenue_cents'] > 0 ? 1 : 0);
        if ($quantity !== 1) {
            $this->text($sheet, 'A'.$row, __('accounting.export.quantity'));
            $sheet->setCellValueExplicit('B'.$row, $quantity, DataType::TYPE_NUMERIC);
            $this->text($sheet, 'C'.$row, __('accounting.export.unit_sale'));
            $this->money($sheet, 'D'.$row, $line['unit_sale_cents'] ?? $order['product_revenue_cents']);
            $quantityCell = 'B'.$row++;
        }
        $sale = 'D'.$row;
        $this->moneyRow($sheet, $row++, __('accounting.export.order_sale_total'), $order['total_cents'], true);
        if ($order['shipping_cents'] !== 0) {
            $this->moneyRow($sheet, $row++, __('accounting.export.shipping_once'), $order['shipping_cents']);
        }
        $unitCost = 'D'.$row;
        $this->label($sheet, $row, __('accounting.export.purchase_unit'));
        $this->input($sheet, $unitCost);
        $sheet->getRowDimension($row++)->setRowHeight(28);
        $cost = $unitCost;
        if ($quantity !== 1) {
            $cost = 'D'.$row;
            $this->formulaRow($sheet, $row++, __('accounting.export.order_cost_total'), '=IF('.$quantityCell.'=0,0,IF(AND(ISNUMBER('.$unitCost.'),'.$unitCost.'>=0),ROUND('.$quantityCell.'*'.$unitCost.',2),""))', true);
        }
        $profit = 'D'.$row;
        $this->formulaRow($sheet, $row++, __('accounting.export.order_profit'), '=IF(AND(ISNUMBER('.$cost.'),'.$cost.'>=0),ROUND('.$sale.'-'.$cost.',2),"")', true);
        if ($line !== null) {
            $this->name($book, $sheet, 'ITEM_'.$line['item_id'].'_UNIT_COST', $unitCost);
            $this->name($book, $sheet, 'ITEM_'.$line['item_id'].'_COST', $cost);
            $this->name($book, $sheet, 'ITEM_'.$line['item_id'].'_PROFIT', $profit);
        }

        return [$sale, $cost, $profit];
    }

    protected function completeSum(array $cells, bool $nonnegative = false): string
    {
        if ($cells === []) {
            return '=0';
        }
        $refs = implode(',', $cells);

        return '=IF(AND(COUNT('.$refs.')='.count($cells).($nonnegative ? ',MIN('.$refs.')>=0' : '').'),SUM('.$refs.'),"")';
    }

    protected function sum(array $cells): string
    {
        return $cells === [] ? '=0' : '=SUM('.implode(',', $cells).')';
    }

    protected function heading(Worksheet $sheet, int $row, string $text, string $background, int $size, string $foreground = 'FFFFFFFF'): void
    {
        $this->mergedText($sheet, 'A'.$row.':D'.$row, $text);
        $sheet->getStyle('A'.$row.':D'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($background);
        $sheet->getStyle('A'.$row.':D'.$row)->getFont()->setBold(true)->setSize($size)->getColor()->setARGB($foreground);
        $sheet->getRowDimension($row)->setRowHeight(28);
    }

    protected function detail(Worksheet $sheet, int $row, string $label, string $value): void
    {
        $this->text($sheet, 'A'.$row, $label);
        $this->mergedText($sheet, 'B'.$row.':D'.$row, $value);
        $sheet->getRowDimension($row)->setRowHeight(22);
    }

    protected function label(Worksheet $sheet, int $row, string $label): void
    {
        $this->mergedText($sheet, 'A'.$row.':C'.$row, $label);
        $sheet->getStyle('A'.$row.':C'.$row)->getAlignment()->setWrapText(true);
    }

    protected function moneyRow(Worksheet $sheet, int $row, string $label, int $cents, bool $bold = false): void
    {
        $this->label($sheet, $row, $label);
        $this->money($sheet, 'D'.$row, $cents);
        $this->rowStyle($sheet, $row, $bold);
    }

    protected function formulaRow(Worksheet $sheet, int $row, string $label, string $formula, bool $bold = false): void
    {
        $this->label($sheet, $row, $label);
        $sheet->setCellValueExplicit('D'.$row, $formula, DataType::TYPE_FORMULA);
        $this->rowStyle($sheet, $row, $bold);
    }

    protected function rowStyle(Worksheet $sheet, int $row, bool $bold): void
    {
        $sheet->getStyle('D'.$row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('A'.$row.':D'.$row)->getFont()->setBold($bold);
        $sheet->getStyle('A'.$row.':D'.$row)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_HAIR)->getColor()->setARGB('FFE4E7EC');
        $sheet->getRowDimension($row)->setRowHeight(23);
    }

    protected function mergedText(Worksheet $sheet, string $range, string $text): void
    {
        $sheet->mergeCells($range);
        $this->text($sheet, explode(':', $range)[0], $text);
    }

    protected function text(Worksheet $sheet, string $cell, string $text): void
    {
        $sheet->setCellValueExplicit($cell, $text, DataType::TYPE_STRING);
    }

    protected function money(Worksheet $sheet, string $cell, int $cents): void
    {
        $sheet->setCellValueExplicit($cell, $cents / 100, DataType::TYPE_NUMERIC);
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0.00');
    }

    protected function name(Spreadsheet $book, Worksheet $sheet, string $name, string $cell): void
    {
        $book->addNamedRange(new NamedRange($name, $sheet, '$'.substr($cell, 0, 1).'$'.substr($cell, 1)));
    }

    protected function input(Worksheet $sheet, string $cell): void
    {
        $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF2CC');
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getCell($cell)->getDataValidation()->setType(DataValidation::TYPE_DECIMAL)
            ->setOperator(DataValidation::OPERATOR_BETWEEN)->setFormula1('0')->setFormula2('9999999999.99')
            ->setAllowBlank(true)->setErrorStyle(DataValidation::STYLE_STOP)->setShowErrorMessage(true)
            ->setErrorTitle(__('accounting.export.number_error_title'))->setError(__('accounting.export.number_error'));
    }
}
