SHOP POS — SHOP COMPUTER VERSION
================================

This version runs on the shop's own Windows computer. It never needs the
internet: sales, stock, purchases, credit, payments, reports — everything
works offline, every day, like Microsoft Office.

>> For the full illustrated guide, double-click "HELP - Read me first.html" <<

FIRST TIME (about 2 minutes)
----------------------------
1. Copy the whole "ShopPOS" folder somewhere permanent, e.g. C:\ShopPOS
   (not the Downloads folder, and not a flash disk).
2. Double-click  Install.bat
   If Windows shows "Windows protected your PC": click "More info" ->
   "Run anyway". If it asks "allow this app to make changes": Yes.
   If Windows Firewall asks about php.exe: tick "Private networks" -> Allow.
   - puts a "Shop POS" icon on the Desktop
   - starts the POS automatically when the computer turns on
   - lets phones/tablets on the shop Wi-Fi use it (Windows may ask
     "Allow?" — click Yes)
3. The POS opens in the browser. Choose ONE:
   a) "Create the owner account" — start a brand-new shop, or
   b) "Import my shop from the website" — copies your existing shop
      (products, stock, customers, sales history, staff, pictures) from the
      online POS. This needs internet ONE time only. Then log in with your
      usual owner email and password.

EVERY DAY
---------
- Double-click "Shop POS" on the Desktop (it also starts by itself when the
  computer turns on). It opens at  http://localhost:8080
- Phones and tablets in the shop: connect to the shop Wi-Fi and open the
  address shown in the black window, e.g.  http://192.168.1.20:8080
  (The Wi-Fi router doesn't need internet — only to be switched on.)
- "Stop POS" shuts it down. Your data stays.

YOUR DATA AND BACKUPS
---------------------
- Everything is stored in the "data" folder (data\pos.sqlite).
- A backup is made automatically every day (last 30 kept) in data\backups.
- In the POS: Settings -> Backups -> "Download current data" to copy it
  to a flash disk now and then. If the computer breaks, that file is your
  shop.
- To move the POS to a new computer: copy the whole ShopPOS folder
  (including "data"), then run Install.bat there.

IF SOMETHING GOES WRONG
-----------------------
- Install closes at once, or "VCRUNTIME140.dll is missing": double-click
  vc_redist.x64.exe in this folder, then run Install.bat again.
- "This site can't be reached": double-click "Shop POS" (Start POS.bat).
- Phones can't connect: they must be on the same Wi-Fi as this computer,
  and this computer's Wi-Fi network should be set to "Private" in Windows.
- Errors are written to data\logs\php-errors.log
