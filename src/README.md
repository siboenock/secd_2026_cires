# Chapter Desk

**Group 3 · Milestone 4 · UNILAK Rwamagana Campus**

SIBOMANA ENOCK (M05578/2026) and Jackson D. SAM (M05283/2026)

Chapter Desk lets a student submit thesis chapters and revisions, and an assigned supervisor request changes or mark a version reviewed. Each version retains its own file and feedback. Students can also share a GitHub commit or pull request link with their supervisor.

## Local PHP and MySQL setup

1. Serve this folder with a local PHP-enabled web server.
2. Import `database/fyp_workspace.sql` into MySQL. The script creates the database and seeds the demo accounts, project, and chapters.
3. Copy `config.example.php` to `config.php`. Enter your local MySQL host, port, username and password.
4. Open the local URL for this folder.

Use PHP 7.4 or newer with PDO MySQL and ZipArchive enabled. For 10 MB uploads, set PHP's `upload_max_filesize` to at least `10M` and `post_max_size` to at least `12M`. Restart Apache after changing these settings.

Uploads go in a private folder beside the server's document root. If Apache cannot write there, set `storage` in `config.php` to a writable absolute path outside the document root. The application serves downloads only to the student or supervisor assigned to the project.

## Demo accounts

| Role | Email | Password |
| --- | --- | --- |
| Student | student@example.test | ChapterDemo2026! |
| Supervisor | supervisor@example.test | ChapterDemo2026! |

These accounts use fictional data and are intended for local testing.

## Test the two journeys

1. Sign in as the student and choose **Methodology**. Upload `samples/Methodology-v1.pdf`. Verify that Version 1 shows **Submitted**.
2. Sign out and sign in as the supervisor. Open **Methodology**, download Version 1, enter feedback such as “Please add the sampling criteria,” choose **Request changes**, and save.
3. Sign back in as the student. Confirm that the feedback is visible and upload `samples/Methodology-v2.pdf` using **Submit revision**.
4. Sign back in as the supervisor. Open Version 2, enter feedback and choose **Mark reviewed · Allow student to continue**.
5. Sign back in as the student. Verify **Reviewed · Continue** and confirm that the history still shows both versions and their separate feedback.
6. To test software progress, share a GitHub commit or pull request URL and a short summary from the student account. Check that the supervisor can see the update. Repository permissions are managed by GitHub.
7. To repeat the demo, use a chapter with no submission or import into a fresh demo database. Reimporting into the existing database preserves prior records.

The app is intended as a local milestone demonstration.
