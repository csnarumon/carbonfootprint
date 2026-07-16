/* ==============================================
   assets/js/cfp-table-mobile.js
   Mobile table accordion — คอลัมน์หลัก + แตะแถวเพื่อขยายดูรายละเอียด
   ใช้ร่วมกับ class: cfp-col-hide, cfp-td-num/cfp-th-num, cfp-td-expand/cfp-th-expand
   ============================================== */

function cfpInitMobileExpand(tableId) {
    var table = document.getElementById(tableId);
    if (!table) { return; }

    var isMobile = window.innerWidth < 768;

    table.querySelectorAll('.cfp-expand-btn').forEach(function (b) { b.remove(); });
    table.querySelectorAll('.cfp-detail-row').forEach(function (r) { r.remove(); });

    if (!isMobile) {
        table.querySelectorAll('.cfp-col-hide').forEach(function (el) { el.style.display = ''; });
        return;
    }

    table.querySelectorAll('.cfp-col-hide').forEach(function (el) { el.style.display = 'none'; });

    table.querySelectorAll('tbody tr').forEach(function (tr) {
        if (tr.classList.contains('cfp-detail-row')) { return; }
        var expandTd = tr.querySelector('td.cfp-td-expand');
        if (!expandTd) { return; }
        expandTd.innerHTML = '<span class="cfp-expand-btn" title="ดูรายละเอียด">&#xF285;</span>';
        tr.style.cursor = 'pointer';
    });
}

function cfpAccordionToggle(tr) {
    var table = tr.closest('table');
    var btn   = tr.querySelector('.cfp-expand-btn');
    if (!btn) { return; }
    var isOpen = (tr.nextElementSibling && tr.nextElementSibling.classList.contains('cfp-detail-row'));
    table.querySelectorAll('.cfp-detail-row').forEach(function (r) { r.remove(); });
    table.querySelectorAll('.cfp-expand-btn.expanded').forEach(function (b) { b.classList.remove('expanded'); });
    if (isOpen) { return; }

    var hiddenCells = tr.querySelectorAll('.cfp-col-hide');
    var headers     = table.querySelectorAll('thead th');
    var html = '<tr class="cfp-detail-row"><td colspan="99"><div class="cfp-detail-body">';
    hiddenCells.forEach(function (cell) {
        var colIdx = Array.prototype.indexOf.call(cell.parentNode.children, cell);
        var label  = headers[colIdx] ? headers[colIdx].textContent.trim() : '';
        if (!label) { return; }
        html += '<div class="cfp-detail-item"><span class="cfp-detail-label">' + label + '</span><span class="cfp-detail-value">' + cell.innerHTML + '</span></div>';
    });
    html += '</div></td></tr>';
    tr.insertAdjacentHTML('afterend', html);
    btn.classList.add('expanded');
}

/* helper: ผูก init (delegation ครั้งเดียว กันปัญหา listener ซ้ำตอน DataTables redraw) + resize */
function cfpBindMobileExpand(tableId) {
    var table = document.getElementById(tableId);
    if (!table) { return; }

    cfpInitMobileExpand(tableId);

    /* event delegation บน table — bind ครั้งเดียว ไม่ซ้ำแม้ redraw กี่ครั้งก็ตาม */
    if (!table.dataset.cfpMobileBound) {
        table.addEventListener('click', function (e) {
            if (window.innerWidth >= 768) { return; }
            if (e.target.closest('button, a, input, select')) { return; }
            var tr = e.target.closest('tbody tr');
            if (!tr || tr.classList.contains('cfp-detail-row')) { return; }
            if (!tr.querySelector('td.cfp-td-expand')) { return; }
            cfpAccordionToggle(tr);
        });
        table.dataset.cfpMobileBound = '1';
    }

    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () { cfpInitMobileExpand(tableId); }, 200);
    });
}
