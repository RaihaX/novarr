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
  home, for example). Away from home, a second address of the server (a Tailscale
  name, for example) can be added in Settings, see **Two addresses** below. When your
  Novarr has sign-in switched on, the Kindle is linked to your account once with a
  short code (no password to type), see **Linking this Kindle to your account** below.
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
tells you in plain words if it can't reach the server or if it isn't Novarr. If the
server needs sign-in, Novarr then offers to link this Kindle to your account (see below).

Tip: you can also give Novarr a gesture (Settings ▸ Taps and gestures ▸ Gesture
manager): look for **Novarr**, **Novarr: next chapter**,
**Novarr: previous chapter**, **Novarr: chapter list**, **Novarr: novel page**,
**Novarr: quick menu** and **Novarr: sync now** in the list of actions (the last four
since 1.11.0; *sync now* works in the file browser too).

## Using it

- **Home** is a shelf, like a Kindle's. At the top, the novel you are reading: its
  cover, the chapter you are on ("Chapter 969 of 1,147"), how many chapters are left
  and roughly how long they will take, and a progress bar. Below, the covers of your
  other novels: the ones you are reading (most recent first), then those with new
  chapters (a black badge on the cover shows how many), then the rest. Swipe left or
  right (or use the page keys / the ‹ › arrows) for more. The buttons at the bottom
  open the **Library**, **Discover** (find and add novels, see below), **Downloads**,
  **More** (since 1.13.0, see [More](#more-lists-requests-new-chapters-stats)),
  **Sync** (it shows how many changes are waiting, e.g. "Sync (3)") and **Settings**.
- **Tap a cover to read**: it opens straight at the chapter you are on. **Hold** a
  cover for the novel's page. A novel with nothing to read yet opens its page.
- Home, the Library and the novel pages always show where *this device* is, even
  before the changes have reached the server.
- **Library** lists every novel with its cover, how many chapters you have read,
  and the new-chapters badge. Tap reads, hold opens the novel's page. Use
  **Search…** and **Show:** (All, Reading, Unread, New chapters, Completed,
  Downloaded on this device). Swipe left/right or use the arrows to turn pages.
- **Books** (since 1.12.0, with a Novarr server that has books: ePubs uploaded or
  imported from Calibre) read exactly like novels and are saved for offline the same
  way. So that thousands of them don't bury your web novels, Home shows a book only
  once you have started it; the others are behind the **Books** button on Home (shown
  when your library has a book), which opens the Library on your books. In the
  Library, the top row switches between *Novels* and *Books*. A book is complete, so
  it has no *Check for new chapters* (on its page, in the quick menu or at its end).
  Since 1.13.0 a **book series** is one row in the Library ("Dune · 3 books"); tap it
  for its books in order (#1, #2, …). A book's page offers **Download the original
  ePub**: the file as it was uploaded (never rebuilt), to read as an ordinary book.
- **Manga** (since 1.13.0, with a Novarr server that offers them as CBZ): manga appear
  in the Library (the top row then switches between *Novels*, *Books* and *Manga*;
  also **More → Manga**). Opening a chapter downloads it as a comic archive (CBZ) with
  its pages scaled to this screen and in greys (much smaller), and KOReader opens it
  like any comic. Turning past the last page opens the next chapter, the chapter is
  marked read when its last page is on screen, and progress syncs like a novel's.
  While you read, the next 3 chapters are fetched ahead (each is a few MB). A started
  manga is on Home like a novel. Manga chapters are never joined, and they have no
  highlights or EPUB.
- **Comics** (since 1.14.0, with a Novarr server that has comics and sends manga as
  CBZ): a comic series is in the Library under *Comics* (also **More → Comics**), and
  each issue or volume is read exactly like a manga chapter (a CBZ scaled to this
  screen, read on its last page, the next issue past it, progress synced, 3 fetched
  ahead). Comic pages turn **left to right**, even when KOReader's default is right
  to left for your manga; only a series that Novarr marks as right-to-left turns the
  other way. Changing the direction yourself in KOReader (on one issue) is kept.
- **Audiobooks** can't play on a Kindle: a series with an audiobook says
  *Audiobook available: listen in the Novarr app* on its page.
- **A novel** shows its cover, how many chapters are left and roughly how long
  they will take, your reading stats (below), and these actions: *Continue / Start
  reading*, *Chapters*, *Check for new chapters*, *Download for offline…*, *Remove
  downloads*, *Mark all as read*, *Download as EPUB* and *About this novel*. Since
  1.13.0, with a server that has them: *Want to read* and *Favourite* (✓ when on; tap
  to switch) and *Add to list…* (your own reading lists, ✓ on those that hold it; *New
  list…* makes one).
- **Continue** opens the same chapter on every device (the Kindle, the phone apps and
  the website; since 1.10.1): the chapter you read last if you have not finished it
  (or are only part-way into it again), otherwise the first unread chapter after it.
  Unread chapters earlier in the novel, and chapters you left half-read long ago, do
  not pull you back. Only reading counts (since 1.10.2): marking chapters read or
  unread by hand (one chapter, *mark all up to here*, *Mark all as read*) changes
  what is read but never makes a chapter "the one you read last"; when such a mark
  covers the chapter Continue pointed at, Continue moves to the first unread chapter
  after it. Reading on this Kindle that has not reached the server yet
  counts at once. *Download for offline… Next N unread* starts at that chapter and
  includes it even when it is marked read but not finished.
- **Chapters** lists every chapter in order and opens on the one you are reading.
  On the right: `40%` for a chapter you are part-way through, ● when it is saved on
  the device, ✓ when it is read. The magnifier at the top left jumps to a chapter
  number. Hold a chapter for: mark read / unread, mark all up to here as read,
  download this chapter, remove this download.

### More: lists, requests, new chapters, stats

Since 1.13.0 Home's **More** button (also *More…* in the Novarr menu while reading)
opens these, each only when your Novarr server offers it:

- **My lists**: *Favourites*, *Want to read* and your own reading lists. A list opens
  its series like the Library (tap reads, hold opens the page).
- **New chapters**: the series you read that got new chapters since you last looked,
  newest first, each opening at the first new chapter. Until the server's feed has
  something, it shows the series of your library with new chapters.
- **Request a book, web novel or manga…**: choose what (books first), type a title,
  an author or an ISBN, and the server searches every source of that kind while a
  small dialog waits (you can stop waiting). Each result says *Request*, *Me too?*
  (someone asked already: you follow their request), *Requested* or *In library*
  (open it). A request can carry a note. Since 1.14.0, with a server that has comics,
  the choice also offers **Comic** (searched like a book: title, author or ISBN).
- **My requests**: what you asked for, with its status (waiting, approved, ready to
  read, declined and why). Tap one to open it when it is ready, cancel
  it while it waits, or see its details.
- **Notifications**: news about your requests. When Home refreshes (at start, on
  Sync) and a request has become ready, a small notice says *Ready to read: X* with
  **Open**; each piece of news is shown once.
- **My stats** (with a server that keeps reading stats): today, this week, all time,
  your streak and top series, as plain text.
- **Manga**, **Comics** (since 1.14.0; each when your library has some) and **Downloads**.

Opening these screens never asks to turn Wi-Fi on (they show what was saved);
changing a list, requesting or cancelling does, like Sync.

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
- **A chapter the server doesn't have yet is never skipped without a word.** When the
  next chapter isn't on the server (and isn't saved on the device), a group ends
  before it, and at the end of the chapter before it Novarr asks: "Chapter 101 isn't
  on the server yet." with **Check for new chapters**, **Skip to Chapter 102** (when a
  later chapter is there) and **Stay here**. Going back still skips such gaps.
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
saved on the device and there is no Wi-Fi, or the server has no text for it yet. A
manga chapter can't be read on the Kindle: Novarr says "Manga can't be read on this
device. Open it on the website or the Android app."

While you read, the status bar at the bottom shows the time, where you are in the
novel, the chapters left and the reading time left, for example
`15:13 · ch 969 of 1,147 · 178 left · 9 h left` ("ch 969" counts the chapters the
server has, in reading order). On a narrow screen "of 1,147" and then the time are
left out. KOReader's status bar normally shows one item at
a time, so in Novarr chapters the plugin makes this line the item shown. Tap the
bottom of the page to cycle to KOReader's other items; nothing is saved, so other
books keep your own status bar choice. If you use *Show all selected items at once*,
the Novarr line is added to the others. Since 1.11.0 the time in the line moves on
every minute, also when you don't turn a page. The time estimate starts
from the length of the chapters (about 250 words a minute) and, once you have finished
three chapters, uses how long you actually take (pauses longer than 5 minutes and
time asleep don't count). Both items can be switched off in Novarr's settings.

At the end of the line Novarr shows whether your reading has reached the server:
"not synced" (or "3 ch not synced" when finished chapters are waiting) until it is
sent, then "synced just now", "synced 12 min ago", "synced 2 h ago" and so on. It
changes to "synced just now" as soon as something is sent, without a page turn. On a
narrow screen "synced …" goes before the time does; "not synced" always stays. Switch
it off with *Sync status in the status bar* in Settings.

**Reading info at the top** (since 1.11.0): *Reading info position* in Novarr ▸
Settings puts the line at the **Bottom** (the default), at the **Top**, or both
(**Top and bottom**). The top uses KOReader's *Alt status bar*, which Novarr switches
on in Novarr chapters only; your other books keep their own setting, and an Alt status
bar you switched on yourself is never switched off by Novarr. With *Top*, the bottom
status bar is left exactly as you have it. The top line is shorter: it shows where you
are, the chapters left and the time left, and "not synced" when something is waiting
("synced … ago" is left out). It shows Novarr's time only when the Alt status bar's
own clock is off. Page number, page count, font size and the other items of the top
bar are set in KOReader's top menu ▸ Status bar ▸ Alt status bar. The top bar only
exists in page mode: in continuous (scroll) mode the line stays at the bottom. A
change applies at once to the chapter you have open.

**The quick menu** (since 1.11.0): hold your finger on the status bar at the bottom
(or on the top bar when it shows the Novarr line) and a small menu opens, with the
novel, the chapter and the whole reading info line at the top, and these buttons:
*Chapter list*, *Novel page*, *Previous chapter*, *Next chapter*, *Sync now* (with
the number of changes waiting, e.g. "Sync now (3)"), *Check for new chapters*,
*Novarr home* and *Close*. It does nothing when the status bar is hidden, and if you
chose KOReader's own *Skim on hold* for the status bar, holding it keeps opening the
skim dialog. Switch it off with *Hold the status bar for the Novarr menu* in Settings.
The *Novarr: quick menu* gesture opens it too.

When the device sleeps, the screen shows the cover of the novel you are reading, the
way a Kindle does with its own books. The plugin gives each chapter the novel's cover
and, the first time, switches KOReader's sleep screen from the "Sleeping" message to
the book cover, stretched to fill the screen (this applies to your other books too;
turning it off puts back your previous sleep screen settings). Turn it off with
*Book cover as sleep screen* in Novarr ▸ Settings.

Novarr chapters don't fill KOReader's History: only the chapter you are reading is
kept there (so *open last file* still works), the earlier ones are taken out.

The menu (tools tab ▸ Novarr) has, while you read a Novarr chapter: *Chapter list*,
*Novel page*, *Novarr home*, *Next chapter*, *Previous chapter* and *Sync now*.

**Leaving a book** (since 1.11.0): closing the reader takes you back to the Novarr
screen you opened the book from, with Home underneath: the novel's page, its chapter
list or the Library (closing that screen shows Home). A book opened from Home goes
back to Home. A chapter KOReader opened by itself has no Novarr screen to go back
to: the one it reopens when it starts goes to Home (with *Open Novarr when KOReader
starts* on), one opened from KOReader's History or file browser goes back there.
Going to the next or previous chapter, or picking one from the chapter list while you
read, doesn't change where you go back to.

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
show up over the next minutes. When a novel of the same title (from another site) is
already in your library, Novarr asks: "Shadow Slave is already in your library." with
**Open it** (its page), **Add anyway** and **Cancel**.

Discover only works where the server allows it: on the server's public address it is
refused even for the owner, and Novarr says "Discover isn't available on this address.
Use it on your home network." (an earlier list of sources is not shown as if it were
current).

Lists, searches and **More…** pages run on the server in the background: the list
shows "Searching NovelPing for "sword"…" (or "Loading Popular from NovelPing…") with a
**Cancel** row, and KOReader stays usable meanwhile (you can open a result already
shown, or leave Discover). Novarr looks every 3 seconds and shows the results as soon
as they are there; a search on some sites goes through extra steps on the server and
can take a minute or two. Novarr gives up after 5 minutes. **Cancel** only stops
waiting (the row then reads "Stopped. Tap to try again."); leaving Discover does the
same, and a result that arrives afterwards is not shown. Wi-Fi is not switched off
while Novarr waits for a search. With an older Novarr server that can't search in the
background, Discover works as before: KOReader waits for each answer behind a
"Searching…" notice. If a site doesn't answer you see the server's reason or "That
source didn't answer. Try again or pick another source." The covers come from
the sites themselves and are kept in their own small folder (at most 8 MB, the
oldest removed first).

### Highlights

Highlight text in a Novarr chapter as in any book (hold on a word, drag, **Highlight**)
and it is sent to the Novarr server, where it shows with the novel on the website (the
website calls them bookmarks): the excerpt (up to 2000 characters; a longer selection is
cut there), the chapter it is in (also inside joined chapters) and a note if you add one.
Adding or changing the note sends it again; deleting the highlight deletes it on the
server too. Like your reading, this works offline: the highlight waits on the device and
is sent the next time there is a connection, and a highlight you delete before it was
sent never leaves the device. Changing only a highlight's style or colour sends nothing.

A novel's page has a **Highlights** row: everything the server has for that novel,
newest first, with the chapter and the note (50 at a time, **More…** for the rest). Tap
one for the whole text and **Delete**. This list needs the network.

Note: the highlight marks *inside the book* are KOReader's and belong to the chapter
file, so they disappear when Novarr removes chapters you have finished (see *Remove
chapters after reading*); the highlights themselves stay on the server and in the
Highlights list. A highlight deleted from that list stays marked in a chapter still on
the device. Highlights in other books are never sent anywhere.

### Read on another device

Since 1.8.0, when you read a novel more recently somewhere else (the website, the iPhone
app, another Kindle), Novarr asks:

    Chapter 124 · 40% was read on iPhone 2 hours ago. Go there?
    [Go to latest]  [Stay here]

**Go to latest** opens that chapter at that place, even if this Kindle remembers a
different page in it. **Stay here** (or a tap outside the box) keeps you where you are, and
Novarr doesn't ask again about that reading; once you read on, this Kindle is the latest.

It asks at these moments:

- **Opening a novel from Novarr's screens** (a tap on Home or the Library, **Continue**, or
  a chapter in the chapter list) while the Kindle is connected: Novarr asks the server
  first (3 seconds at most) and shows the question before the book opens, so whichever
  you choose, only one chapter is loaded. When the Kindle isn't connected yet, the chapter
  opens as usual and the question comes once a connection is there (within 3 minutes).
  When the server doesn't answer, it doesn't try again before opening for a minute.
- **Waking the Kindle up after 5 minutes or more** with a Novarr chapter open. If Novarr
  had switched Wi-Fi off (*Turn Wi-Fi off while reading when everything is saved*), it
  switches it on briefly to check.
- **A Novarr chapter KOReader opened by itself**: the book KOReader reopens when it
  starts, or one opened from KOReader's History or file browser. Here too Novarr may
  switch its own switched-off Wi-Fi on briefly to check.

Turning to the next or previous chapter while reading never asks. It doesn't ask either
when this Kindle has reading of its own newer than the other device's, when the other
place is within 5% of where you are, or when that chapter can't be opened (not downloaded
on the server). An older server without this feature never asks.

Two entries in Novarr's settings:

- **Ask to go to the latest place read on another device** (on by default). Off: Novarr
  never asks and never checks. This Kindle's place is still sent with its name, so your
  other devices can still offer it.
- **Name of this device**: the name your other devices show ("… was read on Kindle").
  Plain letters, digits and punctuation, at most 40 characters; leave it empty to go back
  to the model name.

To tell devices apart, the plugin makes up a random id for this Kindle once and sends it,
with its name ("Kindle", the model such as "Kindle PaperWhite 5", or the name you set),
along with your reading progress.

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
**Sync**). When there is a connection, where you are in a chapter is also sent when
the device goes to sleep, when it wakes up and after 3 minutes without a page turn, so
your other devices pick up where you stopped. Novarr only switches Wi-Fi on by itself when it switched it off itself
(see below). Opening a screen (Home, the Library, a novel, its chapters) never asks
for Wi-Fi: it refreshes quietly when the device is already connected and otherwise
shows what was saved (Home refreshes by itself once Wi-Fi connects). An action that needs the server (**Sync**, a download,
**Discover**, **Link**, *Check for new chapters*, adding a novel) asks "Turn Wi-Fi
on?" (with KOReader's *Action when Wi-Fi is off* set to turn it on, it just does;
set to ignore, it doesn't). If you say **Not now**, or no connection comes within
45 seconds, Novarr says plainly that the action needs a connection.

### Wi-Fi off while reading

To save battery, Novarr switches Wi-Fi off while you read once everything is
saved: what you read has been sent to the server, the next chapters are on the
device (100 ahead, or every chapter the server has), and nothing else of Novarr's is
using the network (a download, an EPUB, an update, a check for new chapters, a
Novarr screen such as Discover). You see nothing
happen: no message, and the status line stays the same.

Wi-Fi comes back **by itself**, without asking, when about 25 chapters are left
(a quarter of the chapters saved ahead), after 20 chapters read (to send them to
the server), when you wake the device or stop turning pages for 3 minutes while
where you are hasn't been sent yet, when you reach a chapter that isn't saved, when
you leave the book, or when you use a Novarr action that needs the server (Sync, a
download, Discover, *Check for new chapters*). It then sends your reading,
looks for new chapters, saves chapters up to 100 ahead again and switches off
again. Turning Wi-Fi on takes a few seconds on a Kindle; you keep reading
meanwhile (if you are waiting for the next chapter you may see "Getting the next
chapter…"). If it can't connect, it tries again quietly after 1, 5 and then 15
minutes.

When the device goes to sleep, Novarr sends where you are first. If Wi-Fi is on, that
happens at once. If Novarr had switched it off, on a Kindle it switches Wi-Fi on for a
few seconds (at most 20) just to send it, and off again, while the sleep screen is
already showing. This is best effort: a Kindle goes fully to sleep after a few seconds
and nothing can hold it back, so if it doesn't make it, it is sent when you wake the
device. Other e-readers switch Wi-Fi off themselves before sleeping, so there it is
only sent when Wi-Fi was still on.

Novarr only ever switches back on a Wi-Fi that *it* switched off. If you switch
Wi-Fi off yourself (KOReader's menu), it stays off until you switch it on. If you
switch it on yourself, Novarr may switch it off again later once everything is
saved.

When you leave the book (back to Novarr, to KOReader's file browser or another book)
or exit or restart KOReader, Wi-Fi that Novarr switched off is switched back on, quietly
and without waiting, so the Kindle's own Home and store find it on as you left it.
Going to the next chapter or the next group of chapters, or the device going to sleep,
is not leaving the book. Wi-Fi you switched off yourself stays off. (If KOReader is
stopped without exiting, a crash or a power cut, Novarr Home switches it back on the
next time it opens.)

Settings shows how much of your reading had Wi-Fi on, under this setting, once you
have read for 10 minutes or more, for example "Wi-Fi on 14 min of 6 h 10 min reading
(last 7 days)". It counts the time a Novarr chapter is open and the device awake (a
chapter left open without turning a page for over 5 minutes counts as 5 minutes).

This is off by default since 1.7.0: Wi-Fi stays on and your place reaches the server
as you read (every 30 seconds of reading and at the end of each chapter), so another device resumes where you stopped. Switch it on with *Turn
Wi-Fi off while reading* in Settings to save battery; your
place is then sent in batches (on sleep, on wake, after 3 minutes idle).

### Two addresses (home and away)

In Settings, **Second address (away from home)** takes another address of the same
Novarr server, for example its Tailscale name (`https://novarr.example.ts.net`).
It is checked like the first one; if it can't be reached right now you can still
**Save anyway**. Both addresses are the same server: saved chapters, covers and
waiting changes are shared, nothing is downloaded twice.

Novarr uses the address that worked last. When the server doesn't answer at that
address, Novarr quietly tries the other one and carries on there if it answers,
without a message. The same happens when something else answers at the home address
instead of Novarr (a hotel or café router's login page, for example). It goes back to
the home address when KOReader starts, when the Wi-Fi connects, when the device wakes
up and when you tap **Sync**; that check never holds up opening a chapter or the "read
on another device" question (it runs a few seconds after them). Settings shows which
one is in use ("Using: home address" / "Using: second address").

**Changing the server address** of the same server (a new IP address, a new name)
keeps everything on the Kindle: saved chapters, changes waiting to be sent, reading
stats and the account link. Novarr recognises the server by an id it gives (current
servers do). For a different server, or an older one that gives no id,
Novarr first tries once to send the waiting changes to the old server (if it answers
within a few seconds), then warns that saved chapters and anything still waiting will
be removed, as before.

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

### Linking this Kindle to your account

Since 1.9.0, a Novarr server with sign-in switched on only talks to devices linked to an
account. Without sign-in (an older server, or sign-in off) nothing changes and none of
this shows.

To link, tap **Link** on Home (it takes the place of **Sync** while the Kindle isn't
linked) or **Link this Kindle** in Settings. Novarr shows a short code in large letters,
for example:

    K7M 4QX

On your phone or computer, open Novarr, go to **Account → Link a device** and enter the
code. The Kindle notices by itself within a few seconds and says "Linked to" and your name;
there is nothing to type on the Kindle. **Cancel** stops waiting. The dialog says until
when the code works ("This code works until 14:35."); when it runs out, tap **Get a new
code**. The Kindle stays on Wi-Fi while it waits.

Once linked, Settings shows **Account:** and your name (tap it to see the account and the
server) and **Unlink this Kindle**, which signs this Kindle out (also on the server when
it is reachable). Unlinked without a connection, the Kindle's access is still listed on
the website and Novarr says so: remove it there under **Account → Devices**. The link
belongs to the server: the home and away addresses (and a new address of the same
server) share it, and switching to a different server forgets it.

If the server stops accepting this Kindle (the link was removed on the website, or the
account was deleted), Novarr tells you once and offers to link again. Until then nothing
is sent and nothing is fetched from the server, so there is no stream of failing
requests in the background; your reading on this Kindle is **kept**, and every change
waiting to be sent goes out once the Kindle is linked again. Chapters saved on the
Kindle can still be read meanwhile.

A **reader** account (one the owner invited to read) can read, sync, check for new
chapters and download, but not add novels: Home has no **Discover** button for it.
Novarr asks the server about the account again at most once a day (when Home refreshes
online) and after any refusal from the server, so a role changed on the website shows
on the Kindle without linking again. A refused change is never thrown away: it stays
waiting and is sent later.

Plugin updates never need the account: the update check and download work linked or not.

### Settings

The rows are grouped under four headings; a long row wraps onto a second line.

**Server and account**
- **Server address** and **Second address (away from home)**.
- When the server needs sign-in: **Link this Kindle**, or **Account:** with your name and
  **Unlink this Kindle** (see above).
- **Name of this device**.

**Reading**
- **Mark chapter read at its end** (on).
- **Join chapters for smooth page turns** (on).
- **Keep font and layout between chapters** (on).
- **Reading info in the status bar** (on), **Reading info position** (Bottom, Top, or
  Top and bottom; Bottom by default), **Show clock while reading** (on) and
  **Sync status in the status bar** (on; part of the reading info line).
- **Hold the status bar for the Novarr menu** (on; the quick menu).
- **Offer the place read on another device** (on).
- **Show covers** (on).
- **Open Novarr when KOReader starts** (on).
- **Book cover as sleep screen**.

**Wi-Fi and storage**
- **Chapters to fetch ahead**: Off, 20, 50, 100 (default) or 200.
- **Turn Wi-Fi off while reading** (off; once everything is saved), with when Wi-Fi comes
  back and, once there is 10 minutes of reading, how much of it had Wi-Fi on.
- **Remove read chapters (keeps the last 2)** (on).
- **Storage used**.

**About and updates**
- **Check for updates automatically** (on) and **Install updates automatically** (off).
- The plugin version, and checking for an update.

### Updating the plugin over Wi-Fi

Your Novarr server carries the latest plugin, so after the first install you don't
need the USB cable again. In Novarr ▸ **Settings**, tap the last row (under *About and updates*, at the end of Settings),
**Novarr plugin 1.7.0 — check for update** (with your version). Novarr asks the server and either says
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
**Update available (1.7.0) — tap to install** (with the new version). Switch this off with *Check for
updates automatically* in Settings.

Novarr never goes back to an older version by itself. Versions are compared as numbers
(1.10.0 is newer than 1.9.0): when the server has an older version than the one on the
Kindle, there is no dot and no automatic install, and checking from Settings says "The
server has an older version (1.9.0). Version 1.10.0 is installed." with **Install
version 1.9.0 anyway** if you really want it. The same version with other files is
still offered as an update.

With **Install updates automatically** switched on (it is off unless you switch
it on), Novarr installs such an update by itself: only while Novarr Home is on screen
with no book open, the device already on Wi-Fi (it never turns Wi-Fi on for this) and
nothing tapped for 30 seconds. It downloads and checks the update exactly as above, just
without the progress window, then asks once: "Novarr was updated to 1.7.0" with
**Restart now** or **Later**; it never restarts KOReader by itself. If the update can't
be installed nothing changes, nothing is shown, and it is tried again the next day at the
earliest (you can still install it from Settings).

If the server says it "doesn't offer plugin updates", it is an older Novarr (update the
server) or a setup without the plugin folder; copy the plugin over USB as in **Install**.

## Where things are kept, and removing everything

- Saved chapters, covers, the list of pending changes and saved lists:
  `koreader/novarr/` (on a Kindle: `/mnt/us/koreader/novarr/`). The joined chapters
  you read are `group_…html` files next to the chapters; they are made from the saved
  chapters, count towards the space Novarr uses, and are removed with them.
  Discover's covers are in `koreader/novarr/discover-covers/` (at most 8 MB), the
  reading stats in `koreader/novarr/stats.json` (the last 8 days, per novel), the
  reading time with Wi-Fi on in `koreader/novarr/wifitime.json` (the last 7 days) and
  the server ids of highlights sent from the device in `koreader/novarr/highlights.json`.
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
  web page, a router login page). Check the port. With a second address set, Novarr
  switches to it by itself when a router's login page answers at home.
- **"This Kindle isn't linked to your Novarr account"**: the server has sign-in on and
  this Kindle isn't linked (or the link was removed). Tap **Link** on Home and enter the
  code on the website (Account → Link a device). Nothing waiting to be sent is lost.
- **"Novarr hasn't downloaded this chapter from its source yet"** or **"has no text on
  the server"**: the server knows the chapter exists but has no text for it yet. Wait
  for Novarr to download it (see the novel's page on the website).
- **Changes not showing on the website**: tap **Sync** on the Home screen. The
  number in brackets is how many changes are still waiting; while reading, the status
  bar says "not synced" until they are sent.
- **"Discover isn't available on this address"**: the server refuses Discover on its
  public address. Use it at home (or through the home/Tailscale address).
- **"Manga can't be read on this device"**: manga chapters are page images; read them on
  the website or in the Android app.
- **The status bar doesn't show the reading info**: the status bar may be switched
  off (Settings ▸ Status bar), or you tapped it over to another item: keep tapping the
  bottom of the page until the Novarr line comes round again. With *Reading info
  position* set to *Top*, the line is in the top bar instead.
- **"linked from a development folder and can't update itself"**: the plugin folder on
  the device is a link to another folder (a developer setup). Copy the real folder instead.
- **KOReader's log**: on a Kindle it is `koreader/crash.log`. Novarr's lines start
  with `Novarr`.
