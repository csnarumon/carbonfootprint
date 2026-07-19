<?php
/* Generate 008_FlowCFP.docx — native Word flowchart (DrawingML shapes, like 002_FlowCRM.docx)
   describing the CFP system end-to-end flow. */

function esc($s) { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function pt2emu($pt) { return (int)round($pt * 12700); }

/* ---------- Shape definitions (x,y,w,h in points) ---------- */
$mainX = 60; $mainW = 300;
$brX   = 420; $brW = 260;

$shapes = array(
    // id, type(prst), text, x, y, w, h, fill(hex), textColor
    array('id'=>1,  'type'=>'flowChartTerminator', 'text'=>'เริ่มต้น',                                                      'x'=>$mainX+70,'y'=>30, 'w'=>160,'h'=>44,'fill'=>'2AABB8'),
    array('id'=>2,  'type'=>'flowChartProcess',     'text'=>'ตั้งค่าข้อมูลพื้นฐาน: บริษัท / Site / หน่วยวัด',                 'x'=>$mainX,'y'=>104,'w'=>$mainW,'h'=>54,'fill'=>'E4F7F9'),
    array('id'=>3,  'type'=>'flowChartProcess',     'text'=>'สร้าง Activity Item + กำหนด Scope / ประเภท',                    'x'=>$mainX,'y'=>188,'w'=>$mainW,'h'=>54,'fill'=>'E4F7F9'),
    array('id'=>4,  'type'=>'flowChartProcess',     'text'=>'จัดการค่า Emission Factor (EF Master)',                         'x'=>$mainX,'y'=>272,'w'=>$mainW,'h'=>54,'fill'=>'E4F7F9'),
    array('id'=>5,  'type'=>'flowChartProcess',     'text'=>'ผูก EF ↔ Activity Item (1 EF ผูกได้หลาย Item)',                 'x'=>$mainX,'y'=>356,'w'=>$mainW,'h'=>54,'fill'=>'E4F7F9'),
    array('id'=>6,  'type'=>'flowChartInputOutput', 'text'=>'กรอกข้อมูลการใช้งานจริง (Data Entry)',                          'x'=>$mainX,'y'=>440,'w'=>$mainW,'h'=>54,'fill'=>'FFF3CD'),
    array('id'=>7,  'type'=>'flowChartProcess',     'text'=>'ระบบคำนวณ CO2e อัตโนมัติ (ปริมาณ × ค่า EF)',                    'x'=>$mainX,'y'=>524,'w'=>$mainW,'h'=>54,'fill'=>'E4F7F9'),
    array('id'=>8,  'type'=>'flowChartProcess',     'text'=>'ส่งอนุมัติ (Submit)',                                           'x'=>$mainX,'y'=>608,'w'=>$mainW,'h'=>44,'fill'=>'E4F7F9'),
    array('id'=>9,  'type'=>'flowChartDecision',    'text'=>'Reviewer ตรวจสอบ ถูกต้อง?',                                     'x'=>$mainX+20,'y'=>682,'w'=>$mainW-40,'h'=>78,'fill'=>'FDEBD3'),
    array('id'=>10, 'type'=>'flowChartDecision',    'text'=>'Approver: เป็นข้อมูลของตัวเองหรือไม่?',                          'x'=>$mainX+20,'y'=>792,'w'=>$mainW-40,'h'=>78,'fill'=>'FDEBD3'),
    array('id'=>11, 'type'=>'flowChartProcess',     'text'=>'Approve สำเร็จ',                                                'x'=>$mainX,'y'=>902,'w'=>$mainW,'h'=>44,'fill'=>'E4F7F9'),
    array('id'=>12, 'type'=>'flowChartProcess',     'text'=>'Close Month → Snapshot ล็อกข้อมูลถาวร',                         'x'=>$mainX,'y'=>978,'w'=>$mainW,'h'=>54,'fill'=>'E4F7F9'),
    array('id'=>13, 'type'=>'flowChartInputOutput', 'text'=>'Dashboard / รายงาน CO2e',                                       'x'=>$mainX,'y'=>1062,'w'=>$mainW,'h'=>54,'fill'=>'FFF3CD'),
    array('id'=>14, 'type'=>'flowChartTerminator',  'text'=>'สิ้นสุด',                                                       'x'=>$mainX+70,'y'=>1146,'w'=>160,'h'=>44,'fill'=>'2AABB8'),

    array('id'=>20, 'type'=>'flowChartProcess',     'text'=>'ไม่ถูกต้อง → Reject กลับไปแก้ไขข้อมูล',                         'x'=>$brX,'y'=>682,'w'=>$brW,'h'=>60,'fill'=>'FADBD8'),
    array('id'=>21, 'type'=>'flowChartProcess',     'text'=>'เป็นข้อมูลตัวเอง → ปฏิเสธอัตโนมัติ (ISO 14064: ห้ามอนุมัติข้อมูลตัวเอง)', 'x'=>$brX,'y'=>792,'w'=>$brW,'h'=>78,'fill'=>'FADBD8'),
);

/* ---------- Arrow (straight line) definitions: [x1,y1,x2,y2,label] ---------- */
function edgeMainCenterX($mainX,$mainW){ return $mainX + $mainW/2; }
$cx = edgeMainCenterX($mainX,$mainW);

$arrows = array();
// vertical chain down the main column
$chain = array(1,2,3,4,5,6,7,8,9);
foreach ($chain as $i => $id) {
    if ($i === count($chain)-1) break;
    $a = null; $b = null;
    foreach ($shapes as $s) { if ($s['id']==$id) $a=$s; }
    foreach ($shapes as $s) { if ($s['id']==$chain[$i+1]) $b=$s; }
    $x = $cx;
    $arrows[] = array($x, $a['y']+$a['h'], $x, $b['y'], '');
}
// 9 (decision) -> 10 (decision), label ถูกต้อง
$s9=null;$s10=null;$s11=null;$s12=null;$s13=null;$s14=null;$s20=null;$s21=null;
foreach ($shapes as $s) {
    if ($s['id']==9) $s9=$s; if ($s['id']==10) $s10=$s; if ($s['id']==11) $s11=$s;
    if ($s['id']==12) $s12=$s; if ($s['id']==13) $s13=$s; if ($s['id']==14) $s14=$s;
    if ($s['id']==20) $s20=$s; if ($s['id']==21) $s21=$s;
}
$arrows[] = array($cx, $s9['y']+$s9['h'], $cx, $s10['y'], 'ถูกต้อง');
$arrows[] = array($cx, $s10['y']+$s10['h'], $cx, $s11['y'], 'ไม่ใช่');
$arrows[] = array($cx, $s11['y']+$s11['h'], $cx, $s12['y'], '');
$arrows[] = array($cx, $s12['y']+$s12['h'], $cx, $s13['y'], '');
$arrows[] = array($cx, $s13['y']+$s13['h'], $cx, $s14['y'], '');

// decision 9 -> branch 20 (right), label "ไม่ถูกต้อง"
$arrows[] = array($mainX+$mainW-20, $s9['y']+$s9['h']/2, $s20['x'], $s20['y']+$s20['h']/2, 'ไม่ถูกต้อง');
// decision 10 -> branch 21 (right), label "ใช่"
$arrows[] = array($mainX+$mainW-20, $s10['y']+$s10['h']/2, $s21['x'], $s21['y']+$s21['h']/2, 'ใช่');

/* loop-back elbow: branch box -> back to box 6 (data entry), via 3-segment bent line drawn as 2 straight segments meeting at a corner x */
$backCornerX = $mainX + $mainW + 40; // between main column and branch column
$s6=null; foreach ($shapes as $s) { if ($s['id']==6) $s6=$s; }
foreach (array($s20,$s21) as $b) {
    $midY = $b['y'] + $b['h']/2;
    $arrows[] = array($b['x'], $midY, $backCornerX, $midY, ''); // left from branch box
    $arrows[] = array($backCornerX, $midY, $backCornerX, $s6['y']+$s6['h']/2, ''); // up to data-entry row
    $arrows[] = array($backCornerX, $s6['y']+$s6['h']/2, $mainX+$mainW, $s6['y']+$s6['h']/2, ''); // into box 6 right edge
}

/* ---------- Build DrawingML ---------- */
$shapeXml = '';
$shapeId = 100;
foreach ($shapes as $s) {
    $shapeId++;
    $x = pt2emu($s['x']); $y = pt2emu($s['y']); $w = pt2emu($s['w']); $h = pt2emu($s['h']);
    $shapeXml .= '<wps:wsp>'
        . '<wps:cNvPr id="'.$shapeId.'" name="Shape'.$shapeId.'"/>'
        . '<wps:cNvSpPr/>'
        . '<wps:spPr><a:xfrm><a:off x="'.$x.'" y="'.$y.'"/><a:ext cx="'.$w.'" cy="'.$h.'"/></a:xfrm>'
        . '<a:prstGeom prst="'.$s['type'].'"><a:avLst/></a:prstGeom>'
        . '<a:solidFill><a:srgbClr val="'.$s['fill'].'"/></a:solidFill>'
        . '<a:ln w="9525"><a:solidFill><a:srgbClr val="1B3A4A"/></a:solidFill></a:ln>'
        . '</wps:spPr>'
        . '<wps:txbx><w:txbxContent><w:p><w:pPr><w:jc w:val="center"/></w:pPr>'
        . '<w:r><w:rPr><w:rFonts w:ascii="TH Sarabun New" w:eastAsia="TH Sarabun New" w:hAnsi="TH Sarabun New" w:cs="TH Sarabun New"/><w:b/><w:color w:val="1B3A4A"/><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr>'
        . '<w:t xml:space="preserve">'.esc($s['text']).'</w:t></w:r></w:p></w:txbxContent></wps:txbx>'
        . '<wps:bodyPr wrap="square" lIns="45720" tIns="27432" rIns="45720" bIns="27432" anchor="ctr"><a:noAutofit/></wps:bodyPr>'
        . '</wps:wsp>';
}

$arrowXml = '';
foreach ($arrows as $a) {
    $shapeId++;
    list($x1,$y1,$x2,$y2,$label) = $a;
    $x = pt2emu(min($x1,$x2)); $y = pt2emu(min($y1,$y2));
    $w = pt2emu(max(1,abs($x2-$x1))); $h = pt2emu(max(1,abs($y2-$y1)));
    $flipH = $x2 < $x1 ? '1' : '0';
    $flipV = $y2 < $y1 ? '1' : '0';
    $arrowXml .= '<wps:wsp>'
        . '<wps:cNvPr id="'.$shapeId.'" name="Line'.$shapeId.'"/>'
        . '<wps:cNvSpPr/>'
        . '<wps:spPr><a:xfrm flipH="'.$flipH.'" flipV="'.$flipV.'"><a:off x="'.$x.'" y="'.$y.'"/><a:ext cx="'.$w.'" cy="'.$h.'"/></a:xfrm>'
        . '<a:prstGeom prst="line"><a:avLst/></a:prstGeom>'
        . '<a:noFill/>'
        . '<a:ln w="19050"><a:solidFill><a:srgbClr val="4A7A88"/></a:solidFill><a:tailEnd type="triangle" w="med" len="med"/></a:ln>'
        . '</wps:spPr>'
        . '<wps:bodyPr/>'
        . '</wps:wsp>';
    if ($label !== '') {
        $lx = pt2emu(min($x1,$x2) + abs($x2-$x1)/2 - 30);
        $ly = pt2emu(min($y1,$y2) - 4);
        $shapeId++;
        $arrowXml .= '<wps:wsp><wps:cNvPr id="'.$shapeId.'" name="Label'.$shapeId.'"/><wps:cNvSpPr/>'
            . '<wps:spPr><a:xfrm><a:off x="'.$lx.'" y="'.$ly.'"/><a:ext cx="'.pt2emu(70).'" cy="'.pt2emu(16).'"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/><a:ln><a:noFill/></a:ln></wps:spPr>'
            . '<wps:txbx><w:txbxContent><w:p><w:pPr><w:jc w:val="center"/></w:pPr>'
            . '<w:r><w:rPr><w:rFonts w:ascii="TH Sarabun New" w:eastAsia="TH Sarabun New" w:hAnsi="TH Sarabun New" w:cs="TH Sarabun New"/><w:i/><w:color w:val="C0392B"/><w:sz w:val="16"/><w:szCs w:val="16"/></w:rPr>'
            . '<w:t xml:space="preserve">'.esc($label).'</w:t></w:r></w:p></w:txbxContent></wps:txbx>'
            . '<wps:bodyPr wrap="none" lIns="0" tIns="0" rIns="0" bIns="0" anchor="ctr"><a:noAutofit/></wps:bodyPr></wps:wsp>';
    }
}

/* group bounding box */
$allX2 = array(); $allY2 = array();
foreach ($shapes as $s) { $allX2[] = $s['x']+$s['w']; $allY2[] = $s['y']+$s['h']; }
$groupW = pt2emu(max($allX2) + 20);
$groupH = pt2emu(max($allY2) + 20);

$groupXml = '<wpg:wgp>'
    . '<wpg:cNvGrpSpPr/>'
    . '<wpg:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$groupW.'" cy="'.$groupH.'"/>'
    . '<a:chOff x="0" y="0"/><a:chExt cx="'.$groupW.'" cy="'.$groupH.'"/></a:xfrm></wpg:grpSpPr>'
    . $shapeXml . $arrowXml
    . '</wpg:wgp>';

$drawing = '<w:p><w:r><w:drawing>'
    . '<wp:inline distT="0" distB="0" distL="0" distR="0">'
    . '<wp:extent cx="'.$groupW.'" cy="'.$groupH.'"/>'
    . '<wp:effectExtent l="0" t="0" r="0" b="0"/>'
    . '<wp:docPr id="1" name="FlowChart"/>'
    . '<wp:cNvGraphicFramePr/>'
    . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
    . '<a:graphicData uri="http://schemas.microsoft.com/office/word/2010/wordprocessingGroup">'
    . $groupXml
    . '</a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';

$titlePage = '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="120"/><w:rPr><w:rFonts w:ascii="TH Sarabun New" w:eastAsia="TH Sarabun New" w:hAnsi="TH Sarabun New" w:cs="TH Sarabun New"/><w:b/><w:sz w:val="36"/><w:szCs w:val="36"/></w:rPr></w:pPr>'
    . '<w:r><w:rPr><w:rFonts w:ascii="TH Sarabun New" w:eastAsia="TH Sarabun New" w:hAnsi="TH Sarabun New" w:cs="TH Sarabun New"/><w:b/><w:sz w:val="36"/><w:szCs w:val="36"/></w:rPr><w:t xml:space="preserve">Flow การทำงานของระบบ Carbon Footprint (CFP)</w:t></w:r></w:p>'
    . '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="360"/><w:rPr><w:rFonts w:ascii="TH Sarabun New" w:eastAsia="TH Sarabun New" w:hAnsi="TH Sarabun New" w:cs="TH Sarabun New"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr></w:pPr>'
    . '<w:r><w:rPr><w:rFonts w:ascii="TH Sarabun New" w:eastAsia="TH Sarabun New" w:hAnsi="TH Sarabun New" w:cs="TH Sarabun New"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr><w:t xml:space="preserve">ตั้งแต่ตั้งค่าข้อมูลพื้นฐาน จนถึงบันทึกข้อมูล อนุมัติ ปิดเดือน และออกรายงาน</w:t></w:r></w:p>';

$documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
    . 'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
    . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
    . 'xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape" '
    . 'xmlns:wpg="http://schemas.microsoft.com/office/word/2010/wordprocessingGroup" '
    . 'xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" '
    . 'mc:Ignorable="wps wpg">'
    . '<w:body>' . $titlePage . $drawing
    . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="700" w:right="700" w:bottom="700" w:left="700" w:header="720" w:footer="720" w:gutter="0"/></w:sectPr>'
    . '</w:body></w:document>';

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
    . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
    . '</Types>';

$rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
    . '</Relationships>';

$docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    . '</Relationships>';

$stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="TH Sarabun New" w:eastAsia="TH Sarabun New" w:hAnsi="TH Sarabun New" w:cs="TH Sarabun New"/><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr></w:rPrDefault></w:docDefaults>'
    . '</w:styles>';

$outPath = __DIR__ . '/008_FlowCFP.docx';
if (file_exists($outPath)) { unlink($outPath); }
$zip = new ZipArchive();
$zip->open($outPath, ZipArchive::CREATE);
$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $rootRels);
$zip->addFromString('word/document.xml', $documentXml);
$zip->addFromString('word/_rels/document.xml.rels', $docRels);
$zip->addFromString('word/styles.xml', $stylesXml);
$zip->close();

echo "Written: $outPath (" . filesize($outPath) . " bytes)\n";
echo "Shapes: " . count($shapes) . ", Arrows: " . count($arrows) . "\n";
