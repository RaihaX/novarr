# Novarr for KOReader

Read the novels on your Novarr server on a Kindle (or any other e-reader running
KOReader), one chapter at a time, straight from the server. New chapters are
readable as soon as Novarr has downloaded them, you can save chapters for reading
without Wi-Fi, and what you read is sent back to Novarr, so the website and the
iPhone app know where you are.

## What you need

- An e-reader running **KOReader**. On a Kindle this means a jailbroken Kindle with
  KOReader installed. The guide at
  <https://kindlemodding.org/jailbreaking/post-jailbreak/koreader.html> explains how;
  which KOReader package you need depends on your Kindle model and firmware, so
  follow that guide rather than guessing.
- Your Novarr server, and the e-reader on the **same network** (the same Wi-Fi at
  home, for example). Novarr has no login, so nothing else is needed. Away from
  home, a second address of the server (a Tailscale name, for example) can be added
  in Settings, see **Two addresses** below.
- KOReader 2024.11 or newer for the reading info in the status bar (older versions
  work, they just don't show it).

## Install

1. Connect the e-reader to your computer with a USB cable.
2. Copy the `novarr.koplugin` folder (from `novarr-koplugin.zip`) into the
   `koreader/plugins/` folder on the device. On a Kindle that is
   `koreader/plugins/` on the Kindle drive.
3. Eject the device and restart KOReader.

## First run

Open the KOReader menu (tap the top of the screen), go to the **tools** tab
(the wrench and screwdriver) and choose **Novarr**. It is there both in the file
browser and while reading.

The first time, Novarr asks for the server address: type the address you use to
open Novarr in a web browser, for example `192.168.1.34:8080`. You can leave out
`http://`; a pasted `/api/v1` at the end is ignored. Novarr checks the address and
tells you in plain words if it can't reach the server or if it isn't Novarr.

Tip: you can also give Novarr a gesture (Settings ▸ Taps and gestures ▸ Gesture
manager): look for **Novarr**, **Novarr: next chapter** and
**Novarr: previous chapter** in the list of actions.

## Using it

- **Home** is a shelf, like a Kindle's. At the top, the novel you are reading: its
  cover, the chapter you are on ("Chapter 969 of 1,147"), how many chapters are left
  and roughly how long they will take, and a progress bar. Below, the covers of your
  other novels: the ones you are reading (most recent first), then those with new
  chapters (a black badge on the cover shows how many), then the rest. Swipe left or
  right (or use the page keys / the ‹ › arrows) for more. The buttons at the bottom
  open the **Library**, **Discover** (find and add novels, see below), **Downloads**,
  **Sync** (it shows how many changes are waiting, e.g. "Sync (3)") and **Settings**.
- **Tap a cover to read**: it opens straight at the chapter you are on. **Hold** a
  cover for the novel's page. A novel with nothing to read yet opens its page.
- Home, the Library and the novel pages always show where *this device* is, even
  before the changes have reached the server.
- **Library** lists every novel with its cover, how many chapters you have read,
  and the new-chapters badge. Tap reads, hold opens the novel's page. Use
  **Search…** and **Show:** (All, Reading, Unread, New chapters, Completed,
  Downloaded on this device). Swipe left/right or use the arrows to turn pages.
- **A novel** shows its cover, how many chapters are left and roughly how long
  they will take, your reading stats (below), and these actions: *Continue / Start
  reading*, *Chapters*, *Check for new chapters*, *Download for offline…*, *Remove
  downloads*, *Mark all as read*, *Download as EPUB* and *About this novel*.
- **Chapters** lists every chapter in order and opens on the one you are reading.
  On the right: `40%` for a chapter you are part-way through, ● when it is saved on
  the device, ✓ when it is read. The magnifier at the top left jumps to a chapter
  number. Hold a chapter for: mark read / unread, mark all up to here as read,
  download this chapter, remove this download.

### Reading

Tap a chapter and it opens in KOReader's normal reader, with all your usual font and
layout settings. When you reach the end of a chapter it is marked read and **the next
chapter opens straight away** (without the "Opening file" box when it is already on the
device). Turning back on the first page of a chapter opens the previous chapter at its
last page, as in one continuous book; that doesn't change what is marked read.

**Chapters are joined in groups of 20** so that going from one chapter to the next is
an ordinary page turn, with no pause to load a new file (on a Kindle that pause is
easy to see). Every chapter still starts on a new page, and everything else stays per
chapter: a chapter is marked read when you turn the page past its end, your place,
the status line and the reading time follow the chapter you are in, and KOReader's
table of contents lists the chapters of the group. The short load happens once every
20 chapters instead of at every chapter. Some details:

- Only chapters you have *paged through to the end* are marked read. Jumping ahead
  (KOReader's go to page, skim, table of contents, bookmarks, or Novarr's *Next
  chapter*) doesn't mark the chapters you skipped.
- A group holds the chapters that are saved on the device side by side: if one in
  the middle isn't saved (or has no text on the server), the group stops there and
  the next one opens as before.
- New chapters saved while you read join the group the next time it is opened; the
  open book is never changed under you.
- If something looks wrong on your device, switch *Join chapters for smooth page
  turns* off in Novarr ▸ Settings: each chapter then opens on its own, exactly as
  before (from the next chapter you open).

When you finish the newest chapter Novarr has, a quiet "You're up to date" page shows
the cover and when the server last got a chapter, with *Check for new chapters*,
*Choose another novel* and *Close*. The check runs on the server (see below); the page
stays open and shows how it goes, and when new chapters arrive it offers *Continue
reading*. You only see a message when the next chapter can't be opened: it isn't
saved on the device and there is no Wi-Fi, or the server has no text for it yet.

While you read, the status bar at the bottom shows the time, where you are in the
novel, the chapters left and the reading time left, for example
`15:13 · ch 969 of 1,147 · 178 left · 9 h left` ("ch 969" counts the chapters the
server has, in reading order). On a narrow screen "of 1,147" and then the time are
left out. KOReader's status bar normally shows one item at
a time, so in Novarr chapters the plugin makes this line the item shown. Tap the
bottom of the page to cycle to KOReader's other items; nothing is saved, so other
books keep your own status bar choice. If you use *Show all selected items at once*,
the Novarr line is added to the others. The time estimate starts
from the length of the chapters (about 250 words a minute) and, once you have finished
three chapters, uses how long you actually take (pauses longer than 5 minutes and
time asleep don't count). Both items can be switched off in Novarr's settings.

When the device sleeps, the screen shows the cover of the novel you are reading, the
way a Kindle does with its own books. The plugin gives each chapter the novel's cover
and, the first time, switches KOReader's sleep screen from the "Sleeping" message to
the book cover, stretched to fill the screen (this applies to your other books too;
turning it off puts back your previous sleep screen settings). Turn it off with
*Sleep screen shows the book cover* in Novarr ▸ Settings.

Novarr chapters don't fill KOReader's History: only the chapter you are reading is
kept there (so *open last file* still works), the earlier ones are taken out.

The menu (tools tab ▸ Novarr) also has *Next chapter*, *Previous chapter*,
*Chapter list* and *Novarr home* while you read a Novarr chapter.

### Check for new chapters

*Check for new chapters* (on a novel's page and on the "You're up to date" page) asks
the Novarr server to look at the novel's source now: it reads the source's chapter
list and downloads the new chapters, as the novel's *Scrape TOC* and *Download
Chapters* buttons do on the website. That can take a few minutes. Meanwhile you can
keep reading or leave the page: Novarr looks at how it is going every 15 seconds
without holding anything up, and the novel's page shows *Checking…*. When it is done
you see "3 new chapters." (or "No new chapters yet.", or the server's reason when it
didn't work); the chapter list is fetched again and, if you are reading that novel,
the new chapters are saved ahead as usual. A check already running on the server
(started on the website, for example) is followed rather than started again. Novarr
stops looking after 10 minutes (the server carries on), and while the device sleeps;
on waking up it looks again. Wi-Fi is not switched off while a check runs.

### Discover: adding novels

**Discover** (on Home, next to Library) finds novels on the sites Novarr reads from,
like the website's Discover page. At the top: **Source** (the sites the server
offers), **List** (Popular, New, Completed, Random, where the site has them) and
**Search…** (type with the on-screen keyboard; some sites can only be searched).
Each result shows its cover, title, author, whether it is ongoing or completed, its
chapter count and **✓ In library** when you already have it. **More…** at the end
loads the next page.

Tap a result for its synopsis and **Add to library** (or **Open** for a novel you
already have). After adding, the result is marked, the novel appears in your Library,
and Novarr asks the server to fetch its chapter list (a check, as above): the chapters
show up over the next minutes.

Lists usually load in a second or two. A search on some sites goes through extra
steps on the server and can take up to two minutes; KOReader waits for it with a
"Searching…" notice (it can't be cancelled midway). If a site doesn't answer you see
"That source didn't answer. Try again or pick another source." The covers come from
the sites themselves and are kept in their own small folder (at most 8 MB, the
oldest removed first).

### Reading stats

A novel's page shows, once there is something to show, how many chapters you read
**today** (since midnight) and **in the last 7 days** (today and the six days before),
about how long a chapter takes you, and about how long it would take to read every
unread chapter, for example:

    Read today: 2 · last 7 days: 9
    About 6 min per chapter · about 3 h to catch up

Only chapters you read to the end on this device count (marking chapters read by hand
doesn't). The time per chapter is your actual reading time (pauses over 5 minutes and
time asleep left out); a chapter read in under 20 seconds or "taking" more than 90
minutes is left out, and it needs three chapters before it shows. Before 1.4 only the
times were kept, so the counts start from the update; the time per chapter can show
right away.

### Without Wi-Fi

Use **Download for offline…** on a novel to save the next 10 or 50 unread chapters,
all unread ones or every chapter. A window shows the progress and has a Cancel
button. While you read online, Novarr also quietly saves the next chapters ahead
(**100** by default; Off, 20, 50, 100 or 200 in Settings), one at a time and never
right after a page turn, so reading stays smooth and you can keep going when the
Wi-Fi drops. When many are missing it fetches them quickly one after another (about
two a second); a page turn waits at most for the one chapter being fetched at that
moment. It stops when less than 20 MB of space is left. If you had chosen 20 yourself
it stays 20; the old default of 20 becomes 100.

Offline, every screen shows what was last loaded (with a small "Offline — showing
saved data" line), saved chapters open as usual, and what you read is remembered
and sent to the server the next time there is a connection (or when you tap
**Sync**). Novarr only switches Wi-Fi on by itself when it switched it off itself
(see below); otherwise, when *you* open Novarr or download something, KOReader asks
to turn Wi-Fi on as it does for its other tools.

### Wi-Fi off while reading

To save battery, Novarr switches Wi-Fi off while you read once everything is
saved: what you read has been sent to the server, the next chapters are on the
device (100 ahead, or every chapter the server has), and nothing else of Novarr's is
using the network (a download, an EPUB, an update, a check for new chapters, a
Novarr screen such as Discover). You see nothing
happen: no message, and the status line stays the same.

Wi-Fi comes back **by itself**, without asking, when about 25 chapters are left
(a quarter of the chapters saved ahead), after 20 chapters read (to send them to
the server), when you reach a chapter that isn't saved, or when you open Novarr
Home or another Novarr screen that needs the server. It then sends your reading,
looks for new chapters, saves chapters up to 100 ahead again and switches off
again. Turning Wi-Fi on takes a few seconds on a Kindle; you keep reading
meanwhile (if you are waiting for the next chapter you may see "Getting the next
chapter…"). If it can't connect, it tries again quietly after 1, 5 and then 15
minutes.

Novarr only ever switches back on a Wi-Fi that *it* switched off. If you switch
Wi-Fi off yourself (KOReader's menu), it stays off until you switch it on. If you
switch it on yourself, Novarr may switch it off again later once everything is
saved. When you close the chapter or open another book, Novarr stops looking after
Wi-Fi and leaves it as it is. Switch all this off with *Turn Wi-Fi off while
reading when everything is saved* in Settings; Novarr then never touches Wi-Fi.

### Two addresses (home and away)

In Settings, **Second address (away from home)** takes another address of the same
Novarr server, for example its Tailscale name (`https://novarr.example.ts.net`).
It is checked like the first one; if it can't be reached right now you can still
**Save anyway**. Both addresses are the same server: saved chapters, covers and
waiting changes are shared, nothing is downloaded twice.

Novarr uses the address that worked last. When the server doesn't answer at that
address, Novarr quietly tries the other one and carries on there if it answers,
without a message. It goes back to the home address when KOReader starts, when the
Wi-Fi connects, when the device wakes up and when you tap **Sync**. Settings shows
which one is in use ("Using: home address" / "Using: second address").

`https://` addresses also work while the KOReader Tailscale plugin is connected
(it routes KOReader's connections through a local proxy); before, only `http://`
addresses did.

By default chapters you have finished are removed from the device as you read on,
keeping the last two (so you can go back a little). With joined chapters this happens
when you move on to the next group of 20: finished chapters of earlier groups go,
except the last two, and nothing in the group you are reading is touched. Unread and
part-read chapters are never removed. Switch this off in Settings if you prefer to
keep everything.

### A whole novel as an EPUB

**Download as EPUB** on a novel's page gets the whole novel as one EPUB book. If the
server hasn't made one yet, or new chapters arrived since, Novarr asks the server to
build it first and waits (with a Cancel button; cancelling only stops waiting, the
server finishes the job and the next tap picks it up). The book is saved in the
`Novarr` folder of KOReader's home folder and opens like any other book.

Note: reading progress in a downloaded EPUB stays on the device and is **not** sent
to Novarr, and the EPUB doesn't get new chapters by itself. Reading chapter by chapter
through Novarr is the way that stays up to date and in sync.

### Settings

Server address and a second address for away from home, how many chapters to save
ahead while reading, whether to turn Wi-Fi off while reading when everything is
saved, remove chapters after reading, mark a chapter read at its end, join chapters for smooth page turns, show
reading info in the status bar, show the clock while reading, show covers, how much
space Novarr uses, whether to check for plugin updates by itself, and the plugin
version.

### Updating the plugin over Wi-Fi

Your Novarr server carries the latest plugin, so after the first install you don't
need the USB cable again. In Novarr ▸ **Settings**, tap the last row,
**Novarr plugin 1.1.0 — check for update**. Novarr asks the server and either says
"Novarr is up to date" or shows the new version with **Install update**. A window shows
the download ("Downloading update… 12 of 27") with a Cancel button. When it is done,
choose **Restart now** (or **Later**: the new version is used the next time KOReader
starts).

The update is safe to interrupt: it is downloaded next to the plugin and checked file by
file first, and the plugin is only replaced once everything has arrived intact. If
anything goes wrong (Wi-Fi drops, the server has a problem, you tap Cancel) you see "Nothing
was changed" and the plugin you have keeps working.

Novarr also looks for an update by itself, at most once a day, when Home loads and the
device is already on Wi-Fi, or while you read before it switches Wi-Fi off (it never
turns Wi-Fi on for this and never interrupts you).
When there is one, Home's button reads **Settings •** and the last Settings row reads
**Update available (1.2.0) — tap to install**. Switch this off with *Check for plugin
updates automatically* in Settings.

If the server says it "doesn't offer plugin updates", it is an older Novarr (update the
server) or a setup without the plugin folder; copy the plugin over USB as in **Install**.

## Where things are kept, and removing everything

- Saved chapters, covers, the list of pending changes and saved lists:
  `koreader/novarr/` (on a Kindle: `/mnt/us/koreader/novarr/`). The joined chapters
  you read are `group_…html` files next to the chapters; they are made from the saved
  chapters, count towards the space Novarr uses, and are removed with them.
  Discover's covers are in `koreader/novarr/discover-covers/` (at most 8 MB), the
  reading stats in `koreader/novarr/stats.json` (the last 8 days, per novel).
- Settings: `koreader/settings/novarr.lua`.
- EPUBs: the `Novarr` folder in KOReader's home folder.

To remove saved chapters use **Downloads** ▸ *Remove all downloads* (EPUBs are listed
there too). To remove the plugin completely, delete `koreader/plugins/novarr.koplugin`,
`koreader/novarr/` and `koreader/settings/novarr.lua`.

## Troubleshooting

- **"Couldn't reach the Novarr server"**: check the address and port (open it in a
  browser on your phone on the same Wi-Fi), that the server is running, and that the
  e-reader is connected to the same network. Away from home, add the server's second
  address in Settings. With KOReader's proxy (or the Tailscale plugin) on, the proxy
  must be running too: Novarr's lines in the log say "proxy step failed" when it isn't.
- **Wi-Fi is off and doesn't come back by itself**: Novarr only switches back on a
  Wi-Fi it switched off; if you switched it off yourself, switch it on in KOReader's
  menu. A failed connection is tried again after 1, 5 and 15 minutes.
- **"isn't a Novarr server"**: the address answers, but with something else (another
  web page, a router login page). Check the port.
- **"Novarr hasn't downloaded this chapter from its source yet"** or **"has no text on
  the server"**: the server knows the chapter exists but has no text for it yet. Wait
  for Novarr to download it (see the novel's page on the website).
- **Changes not showing on the website**: tap **Sync** on the Home screen. The
  number in brackets is how many changes are still waiting.
- **The status bar doesn't show the reading info**: the status bar may be switched
  off (Settings ▸ Status bar), or you tapped it over to another item: keep tapping the
  bottom of the page until the Novarr line comes round again.
- **"linked from a development folder and can't update itself"**: the plugin folder on
  the device is a link to another folder (a developer setup). Copy the real folder instead.
- **KOReader's log**: on a Kindle it is `koreader/crash.log`. Novarr's lines start
  with `Novarr`.
