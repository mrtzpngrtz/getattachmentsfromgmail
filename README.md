# Gmail Attachment Downloader

Connect to your Gmail via IMAP, search your emails, and bulk-download all attachments as a single ZIP file. Read-only — nothing is deleted or modified.

---

## Requirements

- [XAMPP](https://www.apachefriends.org/) (PHP 8.x + Apache)
- A Gmail account with 2-Step Verification enabled
- A Gmail App Password

---

## Installation

### 1. Install XAMPP

Download and install XAMPP from [apachefriends.org](https://www.apachefriends.org/).
Default install path: `C:\xampp` (Windows) or `/opt/lampp` (Linux/Mac).

### 2. Clone or copy the project

Place the project folder inside XAMPP's web root:

```
C:\xampp\htdocs\getattachmentsfromgmail\
```

Or clone via Git:

```bash
cd C:\xampp\htdocs
git clone https://github.com/mrtzpngrtz/getattachmentsfromgmail.git
```

### 3. Enable required PHP extensions

Open `C:\xampp\php\php.ini` in a text editor and make sure these two lines are **not** commented out (remove the leading `;` if present):

```ini
extension=imap
extension=zip
```

### 4. Start Apache

Open the **XAMPP Control Panel** and click **Start** next to Apache.

### 5. Open the app

Go to: **http://localhost/getattachmentsfromgmail/**

---

## Gmail Setup — App Password

Gmail won't accept your regular password over IMAP. You need an **App Password**.

1. Go to [myaccount.google.com](https://myaccount.google.com) → **Security**
2. Under *How you sign in to Google*, click **2-Step Verification** (enable it if not already)
3. Scroll to the bottom and click **App Passwords**
4. Create a new one — name it anything (e.g. "Mail Downloader")
5. Copy the 16-character password shown

Use this password in the app instead of your regular Gmail password.

---

## Usage

1. Open the app and enter your Gmail address + App Password
2. Use the search filters (sender, subject, date range, folder)
3. Check the emails whose attachments you want
4. Click **Download selected as ZIP**

---

## Notes

- The IMAP connection is **read-only** — no emails are moved, deleted, or marked as read
- Search results are capped at 200 emails per query for performance
- Credentials are stored in your PHP session only — nothing is saved to disk

---

Made by [aop.studio](https://aop.studio)
