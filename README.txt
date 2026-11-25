EXAMINATION SEATING ARRANGEMENT - LOCAL SETUP (WITH REGISTER NUMBERS & NAMES)

REQUIREMENTS
- Windows computer
- XAMPP (Apache + MySQL)
- PHP 8.x recommended (ensure extension=zip is enabled in php.ini for .xlsx uploads)
- Web browser

INSTALLATION
1. Install XAMPP and start Apache and MySQL from XAMPP Control Panel.
2. Copy the complete 'exam_seating_app' folder into:
   C:\xampp\htdocs\
   The final path should be C:\xampp\htdocs\exam_seating_app\index.php
3. Open http://localhost/phpmyadmin
4. Note: If you previously imported the old database, drop the old 'exam_seating' database first (or run DROP DATABASE exam_seating;) so the updated tables with register_no and student_name are created cleanly.
5. Click the Import tab, choose database.sql from the exam_seating_app folder, and click Go.
6. Check config.php (default XAMPP: host=localhost, database=exam_seating, user=root, password=blank).
7. Open http://localhost/exam_seating_app/ in your browser.

HOW TO USE
1. Create an examination and choose its date.
2. Under "2. Add Subject & Student List File", select the examination, enter the Subject Code (e.g., CS301), and upload a .csv or .xlsx file containing two columns:
   - Column 1: Register Number (e.g., REG2026001)
   - Column 2: Student Name (e.g., Arjun Menon)
   (You can click "Download sample CSV" on the page for a ready-to-use template.)
3. Repeat Step 2 for every subject code in that examination. The student count is calculated automatically from the uploaded file.
4. Add rooms and enter the number of desks in each room.
5. Click Generate Seating Arrangement.
6. Review both the Bench Chart and the Room-wise Student Register Summary, then click Print Seating Chart.

IMPORTANT RULES
- Room capacity is min(30, desk_count x 2).
- A bench can have one or two students; if two students share a bench, their subject codes must be different.
- Duplicate register numbers within the same subject file are automatically rejected.
