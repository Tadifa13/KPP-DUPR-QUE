# Running it offline on your devices

KAMRYNNE QUE needs **no internet**. It has no CDN, no web fonts, no analytics
and no third-party API. Everything is served from the machine you run it on.

There are two levels of "offline", and it matters which one you need.

| | Works with no internet | Pages stay readable with the **server** unreachable | Installs to home screen |
|---|---|---|---|
| **Plain HTTP** (`./serve.sh`) | Yes, on every device | Only on the machine running it | Bookmark only |
| **HTTPS** (`./serve.sh --https`) | Yes, on every device | Yes, once the certificate is trusted | Yes |

**Most clubs only need plain HTTP.** The organizer runs the server at the court,
everyone else is on the same Wi-Fi, and the server is always reachable — so
browser-side caching adds little. Use `--https` when you want the app installed
as a real app on a phone or tablet, or when you want a device to keep showing
the last screen if it wanders out of Wi-Fi range.

---

## Step 1 — pick the machine that runs it

One device runs the server. Everything else connects to it. That machine needs
PHP 8.1+ with `pdo_sqlite`.

### Mac laptop or Mac mini (recommended)

PHP ships with macOS developer tools. If `php -v` fails:

```bash
xcode-select --install
```

Then, in the project folder:

```bash
./serve.sh
```

It prints both addresses — `http://localhost:8080` for this machine and
`http://<your-lan-ip>:8080` for everyone else.

### Windows laptop

Install PHP from [windows.php.net](https://windows.php.net/download), add it to
`PATH`, enable `extension=pdo_sqlite` in `php.ini`, then from the project folder:

```bash
php -S 0.0.0.0:8080 -t .
```

Find your address with `ipconfig` and use `http://<that-address>:8080`.

### Linux

```bash
sudo apt install php-cli php-sqlite3     # Debian/Ubuntu
./serve.sh
```

### Android phone or tablet as the server

Genuinely practical, and it means the whole system fits in a pocket.

1. Install **Termux** from F-Droid (the Play Store build is outdated).
2. In Termux:

```bash
pkg install php git
git clone https://github.com/Tadifa13/KPP-DUPR-QUE.git
cd KPP-DUPR-QUE
bash serve.sh
```

3. On that same phone open `http://localhost:8080` — which **is** a secure
   context, so you get offline caching and install with no certificate work.
4. Others join the phone's hotspot and use the LAN address it prints.

### iPhone or iPad as the server

Not possible — iOS will not run a PHP server. An iPhone or iPad can only be a
client, connecting to one of the machines above.

---

## Step 2 — connect the other devices

Put every device on the same Wi-Fi as the server. **A phone hotspot with no
internet behind it works perfectly** — the devices only need to see each other,
not the internet.

Open `http://<lan-ip>:8080` in any browser. That is the whole setup for players
and spectators. Court QR codes point at this address, so scanning one just
works.

### Add it to the home screen

- **iPhone / iPad (Safari):** Share → *Add to Home Screen*.
- **Android (Chrome):** ⋮ menu → *Add to Home screen*.
- **Mac (Safari):** File → *Add to Dock*.

Over plain HTTP this gives an icon and a full-screen window. It is a bookmark,
not an installed app — it still needs the server reachable.

---

## Step 3 — optional: HTTPS for true installed apps

Only do this if you want offline caching or a real installed app on devices
other than the server itself.

```bash
./serve.sh --https
```

This issues a self-signed certificate for your current LAN address and serves
on port **8443**. It reissues automatically if your address changes.

> **Clicking through the browser warning is not enough.** Browsers refuse
> service workers and installation on an origin whose certificate is not
> trusted. You must actually install the certificate on each device. It is a
> one-time step per device.

Download it on the device from `https://<lan-ip>:8443/cert.php` — accept the
warning just to reach that page — then:

### iPhone / iPad

1. Safari downloads it as a **configuration profile**.
2. Settings → *Profile Downloaded* → **Install** (top right), enter your
   passcode, **Install** again.
3. **This second step is required and easy to miss:** Settings → General →
   About → **Certificate Trust Settings** → turn the switch on for
   "KAMRYNNE QUE (local)".
4. Open `https://<lan-ip>:8443`, then Share → *Add to Home Screen*.

### Android

1. Chrome downloads the `.crt`.
2. Settings → Security → Encryption & credentials → *Install a certificate* →
   **CA certificate** → pick the downloaded file. Confirm the warning.
3. Some builds put it under Settings → Security → *Install from storage*.
4. Open `https://<lan-ip>:8443` → ⋮ → *Install app*.

### Mac

```bash
sudo security add-trusted-cert -d -r trustRoot \
  -k /Library/Keychains/System.keychain data/tls/cert.pem
```

Or double-click `cert.pem`, find it in Keychain Access → System, and set
**Trust → Always Trust**.

### Windows

Double-click the `.crt` → *Install Certificate* → **Local Machine** → *Place all
certificates in the following store* → **Trusted Root Certification
Authorities**.

### Confirming it worked

Open the app and look at **Play → This device → Offline caching**. It reports
one of:

- *Active* — offline caching is running on this device.
- *Not available on this address* — you are on plain HTTP, or the certificate
  is not trusted yet.

---

## What "offline" does and does not do

**It does:** keep the app fully working with no internet connection, on every
device, permanently. Keep already-opened pages readable if a device loses Wi-Fi.
Let the app be installed like a native app.

**It does not:** let you record scores while the server is unreachable. That is
deliberate. Queued results would let two devices record conflicting outcomes for
the same court, and would show a score as saved when the server never received
it. For a system whose value is a defensible record of who played whom, a wrong
result presented as saved is worse than an honest refusal — so offline writes
fail loudly and you retry.

If the server is on the organizer's own device, that situation barely arises.

---

## Troubleshooting

**Other devices cannot reach it.** Check they are on the same Wi-Fi. macOS may
prompt to allow incoming connections — allow it. Some routers have "client
isolation" or "AP isolation" which blocks device-to-device traffic; turn it off,
or use a phone hotspot instead.

**The address changed.** Home routers hand out new addresses. Re-run
`./serve.sh`, read the new address, and reprint the court codes — they embed the
address. Reserving a static lease for the server machine avoids this.

**Certificate warnings keep coming back.** The certificate is tied to the LAN
address. If the address changed, `--https` issues a new certificate and every
device has to trust the new one. A static lease avoids that too.

**Port already in use.** `./serve.sh 8081` picks another port.

**Nothing at all loads.** Make sure you ran it from the project folder, and that
`data/` is writable.
