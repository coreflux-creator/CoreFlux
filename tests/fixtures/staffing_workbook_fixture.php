<?php
/** Invented workbook bytes for parser and native evidence fixtures. */
function staffingWorkbookFixture(array $replace=[],array $extra=[]): array
{
    $header='';for($i=1;$i<=31;$i++)$header.='<c r="'.staffingWorkbookColumn($i+2).'4"><v>'.$i.'</v></c>';
    $sheet='<worksheet xmlns="'.STAFFING_WORKBOOK_NS.'"><sheetData>'
        .'<row r="1"><c r="A1" t="d"><v>2026-10-01T00:00:00</v></c></row>'
        .'<row r="2"><c r="A2" t="inlineStr"><is><t>Synthetic Person</t></is></c></row>'
        .'<row r="4">'.$header.'</row><row r="5"><c r="C5"><v>8</v></c><c r="D5"><v>8</v></c><c r="E5"><v>0</v></c></row>'
        .'<row r="6"><c r="A6"><f>SUM(C5:AG5)</f><v>16</v></c></row>'
        .'<row r="7"><c r="A7"><v>98</v></c></row><row r="8"><c r="A8"><f>A6*A7</f><v>1568</v></c></row>'
        .'<row r="9"><c r="A9" t="inlineStr"><is><t>USD</t></is></c></row></sheetData></worksheet>';
    $parts=['[Content_Types].xml'=>'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>',
        'xl/workbook.xml'=>'<workbook xmlns="'.STAFFING_WORKBOOK_NS.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Hours" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml'=>strtr($sheet,$replace)];
    $path=tempnam(sys_get_temp_dir(),'cf-workbook-fixture-');$zip=new ZipArchive();
    try{
        if($zip->open($path,ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Fixture archive unavailable');
        foreach(array_replace($parts,$extra)as$name=>$body)$zip->addFromString($name,$body);
        $zip->close();$bytes=file_get_contents($path);
    }finally{unlink($path);}
    return[$bytes,['sheet'=>'Hours','month_cell'=>'A1','identity_cell'=>'A2','day_header_range'=>'C4:AG4','hours_range'=>'C5:AG5',
        'hours_total_cell'=>'A6','bill_rate_cell'=>'A7','bill_total_cell'=>'A8','currency_cell'=>null]];
}
