# PerchPi Setup Guide

PerchPi turns a Raspberry Pi 5 and a small microphone into a bird station. It listens around the clock, names the birds it hears, keeps the recordings, and shows everything on a web page you can open on your phone or computer. A touchscreen is optional.

This guide takes you from a box of parts to your first detected bird. Plan on about an hour, most of it waiting while the memory card is written.

Need help at any point? Email **perchpi@shadyacres.my**.


## Quick start (if you've used a Raspberry Pi before)

1. Buy the parts in section 1. **Use the recommended microphone and adapter.**
2. Install Raspberry Pi Imager 2.0 or newer.
3. In Imager: **App Options → Content Repository → Edit → Use custom URL**, paste the address below, then **Apply & Restart**:
   `https://github.com/churchja/perchpi-release/releases/latest/download/perchpi-os-list.json`
4. Choose **Raspberry Pi 5 → PerchPi v1 → your card**. In the customisation step, set a hostname, a username and password, Wi-Fi, and time zone. Write the card.
5. Plug in the microphone, insert the card, and power on. It restarts once by itself. Allow about 5 minutes.
6. Open `http://<your-hostname>.local/` and log in as `birdnet` / `birdnet`. Then **change that password** and **set your location** (section 8).


## 1. What you need

### Strongly recommended: the exact parts PerchPi is built and tested with

> **⚠ WARNING: use this microphone and this adapter.**
> PerchPi was tuned and tested with the Clippy EM272 microphone plugged into the UGREEN US205 USB sound adapter. With a different microphone, bird detection may be **severely degraded or nearly useless**. PerchPi v1 is also pre-set for the UGREEN US205; with a different USB adapter, the station may **record nothing at all**.

- **Raspberry Pi 5, 8 GB.** This is the tested model.
  https://www.raspberrypi.com/products/raspberry-pi-5/
- **Raspberry Pi Active Cooler**, the official fan and heatsink for the Pi 5. PerchPi runs day and night, and the cooler keeps it from slowing down when it gets hot.
  https://www.raspberrypi.com/products/active-cooler/
- **Raspberry Pi 27W USB-C Power Supply**, the official one.
  https://www.raspberrypi.com/products/27w-power-supply/
- **Samsung microSD card, 64 GB.** 128 GB is also a good choice. Every recording and the bird database live on this card, so more space means more history.
- **Clippy EM272 Mono Microphone** from Micbooster. Order the plain mono version with the 3.5 mm plug, not the XLR or "Smart" versions.
  https://micbooster.com/product/clippy-em272-microphone/
- **UGREEN USB 3.5 mm External Sound Adapter, model US205** (product code 30724). It has two separate round jacks: green for headphones, red for a microphone.

### Optional

- **Touchscreen.** The 8-inch Howens CX080PI-A (1280 × 800, HDMI) is the tested screen. It shows today's birds all day and lets you tap to play their songs. PerchPi works fine without a screen. The Pi 5 has **micro-HDMI** sockets, so you need a micro-HDMI-to-HDMI cable or adapter if the screen doesn't include one.
- **The PerchPi microphone hood**, a small 3D-printed holder that shelters the microphone from rain and wind. Print files and settings:
  https://github.com/churchja/perchpi-release/tree/main/hardware/em272-hood

### You also need

- A computer (Mac, Windows, or Linux) that can read microSD cards. Many laptops have a slot. If yours doesn't, any USB card reader works.
- Your Wi-Fi network name and password. You can use a network cable instead if the Pi sits near your router.


## 2. Put the hardware together

1. **Fit the cooler.** Peel the protective film off the cooler's thermal pads. Line its two spring-loaded pins up with the matching holes in the Pi and press until they click. Plug the cooler's small cable into the 4-pin socket labelled **FAN**.
2. **Connect the microphone.** Plug the Clippy into the **red** (microphone) jack of the UGREEN adapter. It must go in the red jack, not the green one. Then plug the adapter into any USB port on the Pi.
3. **Touchscreen (optional).** Connect it to the Pi's micro-HDMI socket closest to the power socket (HDMI 0). Connect its touch cable to a USB port, following the screen's own instructions.
4. **Leave the power off for now.** The memory card comes first.


## 3. Install Raspberry Pi Imager on your computer

Raspberry Pi Imager is the free program that writes PerchPi onto the memory card.

1. Download it from https://www.raspberrypi.com/software/ and install it like any other program.
2. You need **version 2.0 or newer**. If you already have Imager, update it.


## 4. Point Imager at PerchPi

PerchPi isn't in Imager's built-in list, so you tell Imager where to find it.

### Recommended: through Imager's menus (works on every computer)

1. Open Raspberry Pi Imager.
2. On the first screen, click **App Options**.
3. Next to **Content Repository**, click **Edit**.
4. Choose **Use custom URL** and paste this address exactly:
   ```
   https://github.com/churchja/perchpi-release/releases/latest/download/perchpi-os-list.json
   ```
5. Click **Apply & Restart**. Imager closes and opens again, now showing PerchPi.

### Shortcut for Mac and Linux users comfortable with Terminal

**Mac.** Open the **Terminal** app (press Command-Space and type "Terminal"), paste this line, and press Return. Imager opens with PerchPi's list.
```
osascript -e 'quit app "Raspberry Pi Imager"'; sleep 2; open -a "Raspberry Pi Imager" --args --repo https://github.com/churchja/perchpi-release/releases/latest/download/perchpi-os-list.json
```

**Linux:**
```
rpi-imager --repo https://github.com/churchja/perchpi-release/releases/latest/download/perchpi-os-list.json
```

If the shortcut doesn't work, use the menus above.

> **Don't download the image file and write it with "Use custom".** Imager then skips the customisation step, and the station starts with no login and no Wi-Fi.

To go back to Raspberry Pi's normal list later, open **App Options → Content Repository → Edit** again and switch it back to the default.


## 5. Write the memory card

1. Put the microSD card in your computer.
2. In Imager, choose:
   - **Device:** Raspberry Pi 5
   - **Operating system:** PerchPi v1
   - **Storage:** your microSD card. **Check the size.** Everything on the chosen card or drive is erased.
3. **Customisation.** Imager asks for these settings. Fill them all in:
   - **Hostname:** the station's name on your network, for example `perchpi`. One word: letters, numbers, and hyphens only, no spaces. You'll type it in your browser later. Two stations need two different names.
   - **Username and password:** a login for the Pi itself, used only for advanced maintenance. Write them down. **The PerchPi web page uses a different login** (section 7).
   - **Wi-Fi:** your network name and password, and your **country**.
   - **Time zone:** where the station is.
   - **SSH:** optional, for remote command-line access. If you don't know what this is, leave it off.
4. Click **Write** and confirm. Your computer may ask for **its own** password (the one you use to log in to your computer) so Imager can write to the card. That's normal.
5. Imager downloads PerchPi (about 2 GB), writes it, and checks it. This takes roughly 10 to 30 minutes, depending on your internet and card speed.
6. When Imager says it's finished, take the card out.


## 6. First start

1. Turn the Pi over. Slide the microSD card into the slot on the underside, **metal contacts facing the Pi** (label facing away), until it stops.
2. Make sure the microphone adapter is plugged in.
3. Plug in the power supply. The Pi starts by itself; you don't need to press its small power button.
4. **Wait about 5 minutes.** On its first start, the Pi enlarges its storage and **restarts once by itself**. That's normal. Don't unplug it.
5. With a touchscreen attached, the PerchPi bird overview appears when it's ready.


## 7. Open PerchPi in your browser

On a phone, tablet, or computer **on the same Wi-Fi network**, open a web browser and go to:

```
http://perchpi.local/
```

Use the hostname you picked in section 5. If you named it `backyard`, the address is `http://backyard.local/`.

The page title says **BirdNET-Pi**. That's normal: PerchPi is built on BirdNET-Pi.

Most pages ask for the **web login** the first time you open them. Your browser remembers it after that. Until you change it in section 8, the web login is:

- **Username:** `birdnet`
- **Password:** `birdnet`

This is **not** the username and password you typed in Imager.

**If the address doesn't open:**
- Wait a few more minutes. The first start takes a while.
- Make sure your phone or computer is on the same Wi-Fi as the Pi.
- Some Android phones and older Windows computers can't open `.local` addresses. Open your router's app or web page, find the device with your hostname, and type its number address instead, for example `http://192.168.1.57/`.


## 8. Three settings to change right away

The **gear icon** at the top right of every page opens the settings menu. On the settings pages, **scroll down** to find each section, and click **Update Settings** at the bottom of the page to save.

1. **Change the web password** (important). Gear → **Advanced Settings**. Scroll down to **BirdNET-Pi Password**. Delete everything in the box, then type a new password using **only letters and numbers**. Click **Update Settings**. Your browser asks you to log in again: use `birdnet` and your new password. Write it down.
2. **Set your location.** Gear → **Settings**. Scroll down to **Location**.
   - To find your numbers, open Google Maps on a computer and right-click your house. The first line of the menu shows two numbers, for example `40.71278, -74.00601`.
   - Delete what's in **Latitude** and type the **first** number. Delete what's in **Longitude** and type the **second** number. Keep the minus sign, and use at most **4 digits after the decimal point**, for example `40.7128` and `-74.0060`.
   - Click **Update Settings**.
   - Until you do this, PerchPi uses a location in New York State, so its bird suggestions and weather are wrong.
3. **Check the time zone.** Gear → **Settings**. Scroll down to **Time and Date**. It should already match what you chose in Imager.


## 9. Test it

1. On your phone, play a recording of a bird that's common where you live at this time of year (in most of the US, a Northern Cardinal works well). Hold it about an arm's length from the microphone for 20 to 30 seconds.
2. Within a minute or two, the bird appears on the **Overview** page and under **Birds Today**.
3. Tap the bird to see its picture and play back what PerchPi heard.

If the bird isn't on Overview, look in **Quarantine** in the menu. PerchPi puts birds there that are unlikely for your location and season. If nothing shows up anywhere after 5 minutes, see "Troubleshooting" below.


## 10. Place the microphone

Where the microphone sits matters more than anything else.

- **Only the microphone goes outside.** Keep the Pi, the power supply, and the UGREEN adapter **indoors and dry**.
- **Outside is best for the microphone.** An open or cracked window, a porch, or under an eave all work well. The developer's microphone sits in a second-floor window and hears birds 25 to 35 feet away.
- **Shelter it from rain and wind.** The printed PerchPi hood does both. Wind noise hides bird songs. Push the microphone into the hood (it's a tight fit), then stick the hood to a window frame or another spot outdoors with 3M double-sided outdoor mounting tape.
- **Keep it away from noise:** air conditioners, pumps, wind chimes, busy roads, and the Pi's own fan.
- **The microphone cable is about 1 metre (3 feet).** A USB extension cable between the adapter and the Pi gives you more reach.


## Troubleshooting

- **No birds after several minutes.** Check that the microphone is in the **red** jack and the adapter is plugged into the Pi. Open **Live Audio** in the menu; you should hear the room. If you used a different microphone or adapter, see the warning in section 1.
- **The screen stays black.** Give it the full 5 minutes. The screen must be plugged in before the Pi starts.
- **Lots of wrong birds.** Set your location (section 8). PerchPi uses it to know which birds are likely where you live.
- **Imager doesn't show "PerchPi v1".** Repeat section 4. Check that the whole address was pasted and that you clicked **Apply & Restart**.
- **Imager didn't ask for hostname, Wi-Fi, and password.** You probably used "Use custom". Repeat sections 4 and 5.
- **The web page rejects your password.** Use the **web** login (`birdnet` and the password from section 8), not the one you typed in Imager.

Still stuck? Email **perchpi@shadyacres.my**. Say what you tried and what you see, and include a photo of the screen if you can.

For day-to-day use, see the **PerchPi User Manual**:
https://github.com/churchja/perchpi-release/blob/main/docs/USER-MANUAL.md
