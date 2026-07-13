# CLAUDE.md — CFP (Carbon Footprint Project)

## Active Skills
- **carbonfootprint-ui** — ใช้ทุกครั้งที่สร้างหรือแก้ไข UI component ในระบบนี้
  (System Identity, Design System, Components, Patterns ทั้งหมดอยู่ใน SKILL.md)

---

## Tech Stack (versions)

| Layer | Version |
|-------|---------|
| PHP | 8.3+ (XAMPP) |
| Bootstrap | 5.3 |
| Bootstrap Icons | 1.11 |
| DataTables | 1.13.x |
| DB Driver | `sqlsrv_*` เท่านั้น — ห้ามใช้ `mssql_*` |

---

## Role Hierarchy

| Role ID | ชื่อ | หมายเหตุ |
|---------|------|----------|
| 1 | Data Entry | บันทึกข้อมูล |
| 2 | Reviewer | ตรวจสอบเบื้องต้น (บาง Site) |
| 3 | Approver | อนุมัติข้อมูล CFP |
| 4 | **Admin (IT)** | **สิทธิ์สูงสุดของระบบ** — ควบคุม user/permission, View-as (สวมสิทธิ์ user อื่น แบบ read-only), Elevate (ยกระดับตัวเองชั่วคราวเป็น role อื่น) |
| 5 | Sustainability Admin | สิทธิ์สูงสุด **เชิงธุรกิจ** — อนุมัติ/unlock ข้อมูล CFP ได้ทุก Scope/Site เหมือน Admin แต่ไม่มี View-as/Elevate |
| 6 | Viewer | ดูอย่างเดียว |

- Role 4 และ 5 ผ่าน `requireRole()` ได้ทุกหน้าเสมอ (ดู `includes/auth_check.php`)
- **Admin (Role 4) คือสิทธิ์สูงสุดจริงของระบบ** ไม่ใช่ Sustainability Admin — อย่าสลับความเข้าใจสองอันนี้

---

## Coding Rules

- PHP 8.3+ syntax แต่เขียนแบบ **procedural** (ทีมเดิมอ่านง่าย)
- **แยก PHP logic ออกจาก HTML template** เสมอ
- ห้าม hardcode credential — ใช้ `config/db.php` เสมอ
- ไม่เปลี่ยน DB schema โดยไม่ได้รับการยืนยัน
- หลีกเลี่ยง `DELETE` / `DROP` โดยไม่ confirm
- ใช้ Parameterized Query เสมอ — ห้าม string concat กับ user input

---

## Frontend Rules

- Bootstrap 5 เท่านั้น — ห้ามใช้ Bootstrap 4
- ไม่ redesign UI โดยไม่ได้รับการร้องขอ
- DataTables child rows ต้องไม่ broken
- ทุก string ที่แสดงผล — ใช้ **ภาษาไทย**

---

## File Safety Rules

- วิเคราะห์และระบุไฟล์ที่กระทบก่อนเสมอ
- ถ้ากระทบ **มากกว่า 5 ไฟล์** → ขอ confirm ก่อน
- ไม่แก้ไขไฟล์ที่ไม่เกี่ยวข้อง
- เปลี่ยนแบบ incremental — ทีละน้อย

---

## Debugging Rules

- ระบุ root cause ก่อน fix
- ตรวจสอบ SQL + PHP + JS interaction
- คำนึง production data impact

---

## Communication Style

- อธิบาย / คุยกัน: **ภาษาไทย**
- เขียน code: **ภาษาอังกฤษ**
- Comment: ภาษาอังกฤษ (หรือไทยถ้าเป็น business logic)
- Output: **complete, drop-in ready** — ห้ามตัดด้วย `...`

---

## Execution Philosophy

```
Stability   > Speed
Safety      > Convenience
Minimal     > Refactor
```

## Golden Rule

**If unsure → STOP and ask before coding**