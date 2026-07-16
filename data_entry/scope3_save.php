<?php
/* data_entry/scope3_save.php */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(1,4,5));
header('Content-Type: application/json; charset=utf-8');
$conn=getConnection();$userID=(int)$_SESSION['user_id'];
$isSuperAdmin=isSuperAdmin() && !isViewingAs();
/* Admin/SustainAdmin เข้าตรงๆ (ไม่ผ่าน Elevate เป็น Data Entry) = read-only เช่นกัน */
$canEdit=(getEffectiveRole()===1) && !isViewingAs();

function jsonOut($s,$m,$e=array()){echo json_encode(array_merge(array('success'=>$s,'msg'=>$m),$e),JSON_UNESCAPED_UNICODE);exit;}
if(isViewingAs()){jsonOut(false,'อยู่ในโหมด View-as (Read-only) — บันทึกข้อมูลไม่ได้');}
if(!$canEdit){jsonOut(false,'ไม่มีสิทธิ์บันทึกข้อมูล');}

$raw=file_get_contents('php://input');
$body=json_decode($raw,true);
if(!$body){jsonOut(false,'รูปแบบข้อมูลไม่ถูกต้อง');}
if(empty($body['csrf_token'])||!hash_equals($_SESSION['csrf_token'],$body['csrf_token'])){jsonOut(false,'CSRF ไม่ถูกต้อง');}

$action   =$body['action']   ??'';
$siteID   =(int)($body['siteID']??0);
$yearMonth=trim($body['yearMonth']??'');
$rows     =$body['rows']??array();
$responsibleName   = mb_substr(trim($body['responsibleName'] ?? ''), 0, 200);
$responsibleDeptID = (int)($body['responsibleDeptID'] ?? 0) ?: null;

if(!in_array($action,array('draft','submit','cancel_draft'))){jsonOut(false,'Action ไม่ถูกต้อง');}

/* ── Cancel Draft ── */
if($action==='cancel_draft'){
    if($siteID<=0){jsonOut(false,'ไม่พบ Site');}
    if(empty($yearMonth)||!preg_match('/^\d{6}$/',$yearMonth)){jsonOut(false,'YearMonth ไม่ถูกต้อง');}
    if(!$isSuperAdmin&&getEffectiveRole()===1){
        $resAccCd=sqlsrv_query($conn,"SELECT SiteID FROM CFP_UserScopeAccess WHERE UserID=? AND ScopeNo=3 AND IsActive=1",array($userID));
        $allowedSitesCd=array();
        if($resAccCd){
            while($rCd=sqlsrv_fetch_array($resAccCd,SQLSRV_FETCH_ASSOC)){
                /* SiteID ว่าง = แถวนี้ไม่จำกัด site (เข้าได้ทุก site) เจอแบบนี้แถวเดียวพอ */
                if($rCd['SiteID']){$allowedSitesCd[]=(int)$rCd['SiteID'];}else{$allowedSitesCd=null;break;}
            }
        }
        if($allowedSitesCd!==null&&!in_array($siteID,$allowedSitesCd)){jsonOut(false,'ไม่มีสิทธิ์ยกเลิก Draft ของ Site นี้');}
    }
    $resHdr=sqlsrv_query($conn,
        "SELECT HeaderID,Status FROM CFP_MonthlyHeader WHERE SiteID=? AND YearMonth=? AND Scope='Scope3'",
        array($siteID,$yearMonth));
    $hdrRow=$resHdr?sqlsrv_fetch_array($resHdr,SQLSRV_FETCH_ASSOC):null;
    if(!$hdrRow){jsonOut(false,'ไม่พบข้อมูล Draft');}
    if((int)$hdrRow['Status']!==0){jsonOut(false,'ยกเลิกได้เฉพาะ Draft เท่านั้น');}
    $headerID=(int)$hdrRow['HeaderID'];
    $r1=sqlsrv_query($conn,
        "UPDATE CFP_ActivityData SET IsActive=0,UpdatedBy=?,UpdatedDate=GETDATE() WHERE HeaderID=? AND IsActive=1",
        array($userID,$headerID));
    if($r1===false){$e=sqlsrv_errors();jsonOut(false,'ยกเลิก Draft ไม่สำเร็จ: '.($e[0]['message']??''));}
    /* ลบ Header ทิ้งด้วย เพราะข้อมูลข้างในถูกเคลียร์หมดแล้ว ไม่ปล่อยให้ Header ว่างค้างเป็นขยะ */
    sqlsrv_query($conn,"DELETE FROM CFP_MonthlyHeader WHERE HeaderID=?",array($headerID));
    logAction($conn,'DATA_DELETE','CFP_MonthlyHeader',$headerID,$siteID,$yearMonth,'Scope3','ยกเลิก Draft ทั้งหมด');
    jsonOut(true,'ยกเลิก Draft เรียบร้อยแล้ว');
}
if($siteID<=0){jsonOut(false,'ไม่พบ Site');}
if(empty($yearMonth)||!preg_match('/^\d{6}$/',$yearMonth)){jsonOut(false,'YearMonth ไม่ถูกต้อง');}
if(empty($rows)){jsonOut(false,'ไม่มีข้อมูลที่จะบันทึก');}

/* ── สิทธิ์ ── */
$allowedCats=null;$allowedSites=null;
if(!$isSuperAdmin&&getEffectiveRole()===1){
    $resAcc=sqlsrv_query($conn,"SELECT CategoryIDs,SiteID FROM CFP_UserScopeAccess WHERE UserID=? AND ScopeNo=3 AND IsActive=1",array($userID));
    if($resAcc){
        $allowedCats=array();$allowedSites=array();
        while($r=sqlsrv_fetch_array($resAcc,SQLSRV_FETCH_ASSOC)){
            if(!empty($r['CategoryIDs'])){foreach(explode(',',$r['CategoryIDs'])as $c){$allowedCats[]=(int)trim($c);}}else{$allowedCats=null;}
            if($r['SiteID']){$allowedSites[]=(int)$r['SiteID'];}else{$allowedSites=null;}
        }
    }
    if($allowedSites!==null&&!in_array($siteID,$allowedSites)){jsonOut(false,'ไม่มีสิทธิ์บันทึกข้อมูลของ Site นี้');}
}

/* ── Header Status ── */
$headerStatus=($action==='submit')?1:0;
$resHdr=sqlsrv_query($conn,
    "SELECT HeaderID,Status FROM CFP_MonthlyHeader WHERE SiteID=? AND YearMonth=? AND Scope='Scope3'",
    array($siteID,$yearMonth));
$hdrRow=$resHdr?sqlsrv_fetch_array($resHdr,SQLSRV_FETCH_ASSOC):null;

if($hdrRow){
    $headerID=(int)$hdrRow['HeaderID'];
    $curSt=(int)$hdrRow['Status'];
    if($curSt===2){jsonOut(false,'ข้อมูลถูกอนุมัติแล้ว ไม่สามารถแก้ไขได้');}
    if($curSt===1){jsonOut(false,'ข้อมูลอยู่ในสถานะรออนุมัติ — ให้ผู้ตรวจส่งกลับก่อนแก้ไข');}
    /* Bug fix: CFP_MonthlyHeader ไม่มีคอลัมน์ UpdatedBy/UpdatedDate — query เดิม error ทุกครั้งแบบเงียบๆ */
    $resStUpd = sqlsrv_query($conn,
        "UPDATE CFP_MonthlyHeader SET Status=?, ResponsibleName=?, ResponsibleDeptID=? WHERE HeaderID=?",
        array($headerStatus, $responsibleName ?: null, $responsibleDeptID, $headerID));
    if($resStUpd===false){$e=sqlsrv_errors();jsonOut(false,'อัปเดตสถานะไม่สำเร็จ: '.($e[0]['message']??''));}
    if($action==='submit'){
        sqlsrv_query($conn,"UPDATE CFP_MonthlyHeader SET SubmittedBy=?,SubmittedDate=GETDATE() WHERE HeaderID=?",array($userID,$headerID));
    }
}else{
    $resIns=sqlsrv_query($conn,
        "INSERT INTO CFP_MonthlyHeader (SiteID,YearMonth,Scope,Status,ResponsibleName,ResponsibleDeptID,CreatedBy,CreatedDate) VALUES (?,?,'Scope3',?,?,?,?,GETDATE())",
        array($siteID,$yearMonth,$headerStatus,$responsibleName ?: null,$responsibleDeptID,$userID));
    if(!$resIns){$e=sqlsrv_errors();jsonOut(false,'สร้าง Header ไม่สำเร็จ: '.($e[0]['message']??''));}
    $rID=sqlsrv_query($conn,"SELECT @@IDENTITY AS NewID");
    $rw=sqlsrv_fetch_array($rID,SQLSRV_FETCH_ASSOC);
    $headerID=isset($rw['NewID'])?(int)$rw['NewID']:0;
    if($headerID<=0){jsonOut(false,'ไม่สามารถสร้าง Header ได้');}
    if($action==='submit'){
        sqlsrv_query($conn,"UPDATE CFP_MonthlyHeader SET SubmittedBy=?,SubmittedDate=GETDATE() WHERE HeaderID=?",array($userID,$headerID));
    }
}

/* ── Upsert ActivityData ── */
$successCount=0;$errorCount=0;$errors=array();$savedRows=array();
$year=(int)substr($yearMonth,0,4);

foreach($rows as $row){
    $itemID    =(int)($row['ItemID']??0);
    $qty       =isset($row['Quantity'])?(float)$row['Quantity']:null;
    $cost      =(isset($row['Cost'])&&$row['Cost']!=='')?(float)$row['Cost']:null;
    $remark    =mb_substr(trim($row['Remark']??''),0,500);
    $activityID=(int)($row['DataID']??0);
    $rowKey    =trim($row['RowKey']??'');
    $assetID   =(int)($row['AssetID']??0)?:(null);
    $assetType =mb_substr(trim($row['AssetType']??''),0,30)?:null;
    if($itemID<=0||$qty===null){$errorCount++;continue;}

    /* ตรวจสิทธิ์ Category */
    if(!$isSuperAdmin&&$allowedCats!==null){
        $resCat=sqlsrv_query($conn,
            "SELECT CategoryNo FROM CFP_ActivityItem WHERE ItemID=? AND ScopeNo=3 AND IsActive=1",
            array($itemID));
        $rCat=$resCat?sqlsrv_fetch_array($resCat,SQLSRV_FETCH_ASSOC):null;
        if(!$rCat||!in_array((int)$rCat['CategoryNo'],$allowedCats)){
            $errors[]='ItemID '.$itemID.' ไม่มีสิทธิ์';$errorCount++;continue;
        }
    }

    /* คำนวณ CO2e — ใช้ EF ของปีที่ตรงกับข้อมูล (YearApply <= ปีที่บันทึก) */
    $co2e=null;
    $resEF=sqlsrv_query($conn,
        "SELECT TOP 1 EFValue FROM CFP_EFValue
         WHERE RefTable='CFP_ActivityItem' AND RefID=? AND IsActive=1 AND YearApply<=?
         ORDER BY YearApply DESC",array($itemID,$year));
    if($resEF){$rEF=sqlsrv_fetch_array($resEF,SQLSRV_FETCH_ASSOC);if($rEF){$co2e=$qty*(float)$rEF['EFValue'];}}

    /* ดึง ItemName, CategoryNo สำหรับ INSERT */
    $resItm=sqlsrv_query($conn,
        "SELECT ItemName,CategoryNo,UnitID FROM CFP_ActivityItem WHERE ItemID=? AND IsActive=1",
        array($itemID));
    $rItm=$resItm?sqlsrv_fetch_array($resItm,SQLSRV_FETCH_ASSOC):null;
    $actName=$rItm?$rItm['ItemName']:'';
    $catNo  =$rItm?(int)$rItm['CategoryNo']:0;
    $unitID =$rItm?$rItm['UnitID']:null;

    /* ดึง EFID */
    $efID=null;
    $resEFID=sqlsrv_query($conn,
        "SELECT TOP 1 EFID FROM CFP_EFValue WHERE RefTable='CFP_ActivityItem' AND RefID=? AND IsActive=1 AND YearApply<=? ORDER BY YearApply DESC",
        array($itemID,$year));
    if($resEFID){$rEFID=sqlsrv_fetch_array($resEFID,SQLSRV_FETCH_ASSOC);if($rEFID){$efID=(int)$rEFID['EFID'];}}

    if($activityID>0){
        /* UPDATE — รวม AssetID/AssetType */
        $res=sqlsrv_query($conn,
            "UPDATE CFP_ActivityData
             SET Quantity=?,CO2e=?,Remark=?,AssetID=?,AssetType=?,Cost=?,
                 UpdatedBy=?,UpdatedDate=GETDATE()
             WHERE ActivityID=? AND HeaderID=?",
            array($qty,$co2e,$remark?:null,$assetID,$assetType,$cost,$userID,$activityID,$headerID));
    }else{
        /* INSERT รวม AssetID/AssetType */
        $res=sqlsrv_query($conn,
            "INSERT INTO CFP_ActivityData
             (HeaderID,SiteID,Scope,Category,ActivityName,Quantity,UnitID,EFID,CO2e,
              InputMethod,IsActive,Remark,ItemID,CategoryNo,AssetID,AssetType,Cost,
              CreatedBy,CreatedDate)
             VALUES (?,?,'Scope3',?,?,?,?,?,?,1,1,?,?,?,?,?,?,?,GETDATE())",
            array($headerID,$siteID,
                  'CAT'.$catNo,$actName,
                  $qty,$unitID,$efID,$co2e,
                  $remark?:null,$itemID,$catNo,
                  $assetID,$assetType,$cost,
                  $userID));
        if($res!==false){
            $rIdent=sqlsrv_query($conn,"SELECT @@IDENTITY AS NewID");
            $rwIdent=$rIdent?sqlsrv_fetch_array($rIdent,SQLSRV_FETCH_ASSOC):null;
            $activityID=$rwIdent?(int)$rwIdent['NewID']:0;
        }
    }

    if($res!==false){$successCount++;if($rowKey!==''){$savedRows[]=array('rowKey'=>$rowKey,'activityID'=>$activityID);}}
    else{$errorCount++;$e=sqlsrv_errors();$errors[]=($e[0]['message']??'error').' ItemID='.$itemID;}

    logAction($conn,
        $action==='submit'?'SUBMIT':'DATA_DRAFT',
        'CFP_ActivityData',$activityID?:null,
        $siteID,$yearMonth,'Scope3',
        ($action==='submit'?'ส่งอนุมัติ':'บันทึกร่าง').' ItemID='.$itemID.($assetID?' AssetID='.$assetID:'')
    );
}

if($successCount===0&&$errorCount>0){
    jsonOut(false,'บันทึกไม่สำเร็จ: '.implode('; ',array_slice($errors,0,3)));
}
$msg=($action==='submit')
    ?"ส่งอนุมัติ $successCount รายการเรียบร้อยแล้ว"
    :"บันทึกร่าง $successCount รายการเรียบร้อยแล้ว";
if($errorCount>0){$msg.=" (มีข้อผิดพลาด $errorCount รายการ)";}
jsonOut(true,$msg,array('saved'=>$successCount,'errors'=>$errorCount,'headerID'=>$headerID,'savedRows'=>$savedRows));
