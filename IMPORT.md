# Importing plans and customers

If you are moving from another system, or you have your customers in a spreadsheet, you can add them all at once
instead of typing them into the panel one by one. This guide walks you through it from the start. You do not need
to know anything about CSV files before you begin.

**Contents**

1. [How import works](#1-how-import-works)
2. [Before you start](#2-before-you-start)
3. [Step by step](#3-step-by-step)
4. [Rules for every file](#4-rules-for-every-file)
5. [The plans file](#5-the-plans-file)
6. [The customers file](#6-the-customers-file)
7. [After the import](#7-after-the-import)
8. [Problems and how to fix them](#8-problems-and-how-to-fix-them)

## 1. How import works

- You import from the panel: **System, Import**. There are two files: one for **plans** and one for **customers**.
- Each file is a **CSV file**: a plain spreadsheet saved as text, one row per plan or customer. Excel, Google
  Sheets and LibreOffice can all open and save it.
- You start from a **template** that the panel gives you. It has the right column names and one example row.
- You first **check** the file. The panel reads every row and lists every problem it finds, with the row number and
  the column. Nothing is saved during a check.
- When the check finds no problems, you **import**. All rows are saved together. If something goes wrong, nothing is
  saved, so you never end up with half a file imported. Fix the problem and try again.

## 2. Before you start

Do these in the panel first, because the files refer to them by name:

1. **Plans before customers.** Customers refer to their plan by its **slug** (a short code such as `home_25`). Import
   the plans file first, or create the plans under **Plans**.
2. **Pools.** If you want to give a pool name in a file, create the pool first under **Network, Pools**, with the
   same name as on your NAS. You can leave the pool empty and set it later.
3. **Connection types.** If you use the `connection_type` column, create the types first under
   **System, Connection types** (for example `Home` or `Office`).
4. **IP addresses.** IPoE customers, and customers with a static IP, need their address to be in the IP inventory.
   Add the range under **Network, IP ranges** first. For IPoE customers it must be an IPoE range.
5. **App settings** (**System, App settings**):
   - **Phone digits:** mobile numbers in the file must have exactly this many digits (10 by default).
   - **Required customer fields:** the fields you tick here must be filled in for every customer in the file too.
   - **Hotspot:** Hotspot customers can only be imported when Hotspot is turned on.
6. **Your role.** In **System, Roles** your role needs **Import customers and plans**, and also the permission to add
   what you import: **Add, edit and delete plans** for plans, **Add customers** for customers. The Import page only
   shows the files your role may import.

## 3. Step by step

1. Open **System, Import** in the panel.
2. Click **Download template** on the **Plans** card (or the **Customers** card). A file such as
   `plans-template.csv` is downloaded.
3. Open the template in your spreadsheet program.
   - **Excel:** before you type anything, select the `username`, `password`, `mobile`, `whatsapp`, `alt_number`,
     `postal_code` and `expiry` columns, right-click, **Format Cells**, choose **Text**. Otherwise Excel changes
     them: long numbers become `9.87654E+09`, leading zeros disappear and dates change their format.
   - **Google Sheets:** select the same columns, then **Format, Number, Plain text**.
4. Row 1 holds the column names. **Do not change them.** You may delete columns you do not need, except the
   required ones (see sections 5 and 6), and the order of the columns does not matter.
5. Row 2 is an example. Read it to see what a filled row looks like, then **delete it** or type over it.
6. Fill in one plan or one customer per row. Do not leave empty rows between them (empty rows are skipped, but they
   make the row numbers in the problem list harder to follow).
7. Save the file as CSV:
   - **Excel:** **File, Save As**, and pick **CSV UTF-8 (Comma delimited) (\*.csv)**.
   - **Google Sheets:** **File, Download, Comma-separated values (.csv)**.
   - **LibreOffice:** **File, Save As**, pick **Text CSV (.csv)**, keep the field delimiter `,` and the character
     set **Unicode (UTF-8)**.
8. Back on the Import page, choose the file under **CSV file** on the right card and click **Check file**.
9. If the panel shows a list of problems, fix each one in your spreadsheet (the **Row** number is the row in your
   spreadsheet), save the CSV again, choose it again and click **Check file** again.
10. When the panel says the rows are ready, click **Import**.
11. Do the same for the customers file.

## 4. Rules for every file

- **English only.** Every cell must use English letters (A to Z), digits and normal symbols such as `.`, `,`, `-`,
  `/` and `@`. Hindi, Bengali or other scripts, letters with accents (such as `é`) and line breaks inside a cell are
  refused. Write names and addresses in English letters, for example `Ram Kumar` instead of `राम कुमार`.
- **The first row is the header** with the column names from the template. A column the panel does not know, a
  column that is there twice, or a column without a name is refused.
- **Commas inside a cell** are fine when your spreadsheet program saves the file (it puts the cell in quotes). If you
  edit the CSV in a text editor, put quotes around any cell that has a comma: `"Home plan, 25 Mbps"`.
- **Size:** up to 5,000 rows and 2 MB per file. Split a larger list into several files.
- **Spaces** before and after a value are removed.
- **Capital letters:** plan slugs, pools, connection types, status and service type can be in any case
  (`active`, `Active` and `ACTIVE` all work). Usernames and passwords are kept exactly as you typed them.

## 5. The plans file

Every column marked **Required** must be in the header and filled in on every row.

| Column | Required | What to write | Example |
|---|---|---|---|
| `slug` | Required | A short code for the plan. Small letters, digits, `-` and `_` only, no spaces. Every plan needs its own slug. Customers refer to the plan by it. | `home_25` |
| `name` | Required | The plan name your staff and customers see. | `Home 25` |
| `validity_days` | Required | How many days one recharge lasts, from 1 to 3650. | `30` |
| `price` | Required | The price without a currency symbol. Use the decimals your currency uses (none, 2 or 3). | `599` |
| `upload` | Required | Upload speed: a whole number followed by `k` (kbps) or `M` (Mbps). | `25M` |
| `download` | Required | Download speed, written the same way. | `25M` |
| `pool` | Optional | The name of a pool from **Network, Pools**. Leave it empty to set it later. | `pppoe` |
| `description` | Optional | A note about the plan, up to 1,000 characters. | `Home plan, 25 Mbps` |

The smallest plans file you can import:

```csv
slug,name,validity_days,price,upload,download
home_25,Home 25,30,599,25M,25M
office_50,Office 50,30,999,50M,50M
```

Burst speeds, data limits, time slots and the other plan settings are not in the file. Set them on the plan's page
after the import.

## 6. The customers file

Only three columns must be in the header: `username`, `service_type` and `fullname`. Every other column can be left
out of the file, or left empty for a customer.

### Always required

| Column | What to write | Example |
|---|---|---|
| `username` | The login name. Letters, digits, `.`, `-`, `_` and `@` only, no spaces, up to 64 characters. Every customer needs their own. | `alice` |
| `service_type` | How the customer connects: `PPPoE` (you can also write `PPP`), `IPoE`, or `Hotspot` (only when Hotspot is on). | `PPPoE` |
| `fullname` | The customer's name, up to 100 characters. | `Alice Rao` |

### Required in some cases

| Column | Required when | What to write | Example |
|---|---|---|---|
| `password` | PPPoE and Hotspot customers | The login password. Letters, digits and symbols, no spaces, no quotes (`"` `'`), backticks or backslashes. IPoE customers need none; leave it empty. | `secret12` |
| `ip` | IPoE customers | The customer device's IP address. It must be in an IPoE range under **Network, IP ranges** and not used by anyone else. For PPPoE and Hotspot customers it is optional and gives them a static IP. | `10.60.1.20` |
| `plan_slug` and `expiry` | You give either one, or `status` is `Active` | Fill in both or neither. `plan_slug` is the slug of an existing plan. `expiry` is when the plan ends: `YYYY-MM-DD HH:MM` (24-hour clock), or `YYYY-MM-DD` for midnight. | `home_25` and `2026-11-30 23:59` |
| `line1` | You fill in any address column | The first address line. | `12 Park Street` |
| Contact columns | You ticked them under **Required customer fields** in App settings | See the table below. | |

### Optional

If you leave one of these empty, the customer gets the value in the **If empty** column.

| Column | What to write | If empty |
|---|---|---|
| `status` | `Active`, `Inactive` or `Suspended`. An `Active` customer needs a plan and an expiry. | `Inactive` |
| `mobile`, `whatsapp`, `alt_number` | Digits only, exactly the number of digits set in App settings. No `+91`, no spaces, no dashes. | none |
| `email` | An email address. | none |
| `company` | The company name. | none |
| `tax_id` | The customer's tax number. With India GST it must be a valid GSTIN, e.g. `09ABCDE1234F1Z5`. | none |
| `business_id` | Any other registration number. | none |
| `device_limit` | How many devices may be online with this login, 0 to 100. `0` means no limit. | `1` |
| `mac` | Binds the customer's device before their first login, e.g. `AA:BB:CC:DD:EE:FF`. `AA-BB-CC-DD-EE-FF` and `AABBCCDDEEFF` work too. | learned at the first login |
| `pool` | The name of a pool from **Network, Pools**. Not for IPoE customers. | the pool from App settings, or the plan's pool |
| `connection_type` | The name of a connection type from **System, Connection types**. | none |
| `line2`, `area`, `city`, `state`, `postal_code`, `country` | The rest of the address. If you fill in any of them, fill in `line1` too. | none |
| `auto_renew` | `yes` or `no`. Renews the plan by itself when it ends. Needs the **Recharge** permission. | `no` |
| `free` | `yes` or `no`. A free customer is charged 0 and gets no invoices. Needs the **Free customers, discounts and place of supply** permission. | `no` |
| `discount_percent` | 0 to 100. Needs the same permission as `free`. | `0` |
| `payment_terms_days` | Days until an invoice is due, 0 to 365. `0` means due on receipt. | the default in billing settings |
| `billing_name` | The name to print on invoices, if it is not the customer's name. | the company, else the customer's name |
| `place_of_supply` | India GST only, and only with a `tax_id`: the state's name or GST code, e.g. `West Bengal` or `19`. Needs the same permission as `free`. | your own state |

### Examples

The smallest customers file. These customers are saved as **Inactive** with no plan: they cannot log in until you
recharge them in the panel.

```csv
username,service_type,fullname,password
alice,PPPoE,Alice Rao,secret12
bob,PPPoE,Bob Das,pass4567
```

Customers who can log in as soon as they are imported: give each one a plan, an expiry and the status `Active`.

```csv
username,password,service_type,fullname,mobile,plan_slug,expiry,status
alice,secret12,PPPoE,Alice Rao,9876543210,home_25,2026-11-30 23:59,Active
bob,pass4567,PPPoE,Bob Das,9876501234,office_50,2026-12-15,Active
```

An IPoE customer: no password, but an IP address from an IPoE range.

```csv
username,service_type,fullname,ip,plan_slug,expiry,status
shop12,IPoE,Shop 12,10.60.1.20,home_25,2026-11-30 23:59,Active
```

## 7. After the import

- Each imported plan and customer is written to FreeRADIUS at once. An `Active` customer whose expiry is still ahead
  can log in right away. If the expiry has already passed, the customer is treated as expired, as in the panel.
- The import does not record a recharge or a payment and does not make invoices. The next recharge or renewal
  does that as usual.
- The import itself sends no SMS or WhatsApp message. Your notification rules (for example expiry reminders) apply
  to imported customers like to any other.
- Every imported plan and customer gets an **Imported** entry in its history, with who imported it and when.
- To change something after the import, edit the plan or customer in the panel. Importing the same file again does
  not update anyone: the panel refuses usernames and slugs that already exist.

## 8. Problems and how to fix them

The problem list shows the **row** (as in your spreadsheet) and the **column**. Common ones:

| Problem | What to do |
|---|---|
| Use English letters, digits and symbols only. | The cell has Hindi or another script, an accented letter or a line break. Type it in English letters. |
| Excel turned letters it could not save into ?. Type this in English letters. | The cell had letters in another script, and Excel saved them as `???`. Type it in English letters. |
| Excel turned this into a number. | Excel changed a long number to something like `9.87654E+09`. Format the column as Text (section 3, step 3) and type the value again. |
| The file is not UTF-8 text. | Save the file again as **CSV UTF-8** (section 3, step 7). |
| Unknown column, or Missing column | A column name in row 1 was changed or deleted. Copy the header row from a fresh template. |
| This row has 9 cells but the header has 8. Check for a stray comma. | A cell has a comma that is not in quotes. Save the file from your spreadsheet program instead of a text editor. |
| No plan with this slug. | Import the plans first, or check the spelling of the slug. |
| Give the expiry for this plan. / Give a plan with the expiry. | Fill in both `plan_slug` and `expiry`, or neither. |
| Use YYYY-MM-DD HH:MM, e.g. 2026-11-30 23:59. | Write the date as year, month, day, e.g. `2026-11-30`. If Excel changed it to `30/11/2026`, format the column as Text and type it again. |
| An Active customer needs a plan and an expiry. | Add the plan and expiry, or set `status` to `Inactive`. |
| Enter 10 digits. | Write the mobile number with exactly that many digits, without `+91`, `0`, spaces or dashes. |
| The username has already been taken. | A customer with this username is already in the panel. Use another username, or remove the row. |
| Row 3 already uses this username. | The same username is twice in the file. |
| A deleted customer uses this username. Restore it instead. | Restore that customer under **Customers** (status **Deleted**), or use another username. |
| This address is not in the IP inventory. | Add the IP range under **Network, IP ranges** first. |
| No pool named ..., or No connection type named ... | Create it in the panel first, or check the spelling. |
| Choose PPPoE or IPoE. Hotspot is turned off in App settings. | Write `PPPoE` or `IPoE`, or turn Hotspot on in App settings. |
| You may not set customer pricing. | Your role cannot set `free`, `discount_percent` or `place_of_supply`. Leave them empty, or ask an admin to import. |
| You may not recharge customers, so auto renew stays off. | Your role cannot turn on `auto_renew`. Leave it empty, or ask an admin to import. |

If the panel says **A record in this file was taken meanwhile by someone else**, someone added a customer or plan
with the same username or slug while you were importing. Click **Check file** again to see which row.
