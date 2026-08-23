# Seating Plan

Seating Plan turns a classroom into something you can see. Draw a room's furniture once, seat your
students in it, and then use that picture to take the register, pick a name, record a reward or a
sanction, and log who has left the room. It reads your timetable, your classes, your rosters and
your attendance codes from Gibbon itself rather than keeping a separate copy of any of it, so a
register taken from a seating plan is an ordinary Gibbon attendance record and shows up everywhere
attendance normally does.

Built for Gibbon `v30.0.00+`.

## Contents

- [What this module does](#what-this-module-does)
- [Requirements](#requirements)
- [Installation](#installation)
- [Core concepts](#core-concepts)
- [Quick start](#quick-start)
- [My Lessons](#my-lessons)
- [The room screen](#the-room-screen)
- [Furniture: drawing a room](#furniture-drawing-a-room)
- [Seating: putting students in it](#seating-putting-students-in-it)
- [Badges](#badges)
- [Register: taking attendance](#register-taking-attendance)
- [Picker: choosing a student](#picker-choosing-a-student)
- [Rewards and sanctions](#rewards-and-sanctions)
- [Room exits](#room-exits)
- [Using a colleague's layout](#using-a-colleagues-layout)
- [Importing and exporting layouts](#importing-and-exporting-layouts)
- [Reports](#reports)
- [Correcting a record](#correcting-a-record)
- [Settings](#settings)
- [Permissions](#permissions)
- [Troubleshooting](#troubleshooting)
- [What this module does not do](#what-this-module-does-not-do)
- [Support](#support)

## What this module does

- Lets you draw a room's furniture — desks, chairs, the board, the door — on a grid, and share that
  drawing with colleagues who teach in the same room.
- Seats the students who are actually timetabled into that room at that time, with their photos,
  pulled live from Gibbon when the screen is drawn.
- Takes the register from the seating plan. The rows it writes are ordinary Gibbon attendance
  records, indistinguishable from ones taken through the Attendance module.
- Picks a student at random for questioning, working through the class rather than repeating the
  same few names.
- Records rewards and sanctions with a tap, and can optionally write them into the Behaviour module
  once a student reaches a threshold you set.
- Logs a student out of the room and back in again, with the time they were gone.
- Reports on all of it — by student, class, subject, year group, form group, teacher, room or date.

## Requirements

- Gibbon `v30.0.00` or later.
- A Gibbon Admin account to install the module.
- A timetable with rooms on it. Seating, Register, Picker, Rewards and Room Exits all need to know
  which class is in which room at which time, and they read that from Timetable Admin.
- Student photos are used where they exist. Where they don't, a placeholder is shown instead —
  nothing breaks.

## Installation

1. Copy the `Seating Plan` folder into your Gibbon installation's `modules/` directory.
2. Log in as an Administrator and go to **Admin > System Admin > Manage Modules**.
3. Find **Seating Plan** in the list and click **Install**.
4. Optionally visit **Learn > Seating Plan > Behaviour Settings** and **Badge Settings** (see
   [Settings](#settings)). Neither is required — the module works without touching either.

Upgrading from an earlier version is the **Update** button on the same Manage Modules page. It
never drops a table or removes a layout anybody has drawn.

## Core concepts

Four things are worth understanding before you start:

- **Room layout** — the furniture in a room, drawn on a grid. It belongs to whoever drew it, and
  can be shared with colleagues. It has nothing to do with any particular class.
- **Seating plan** — which students sit on which chairs. A plan belongs to one layout plus one set
  of classes, so a group that meets in the same room at several different times uses one plan for
  all of them.
- **The room screen** — one full-screen page that shows the room, with several **modes** along the
  top. The room is the same in every mode; what a click means changes.
- **Lesson** — one date and one timetabled period. Rewards, sanctions and room exits are all
  recorded against a lesson, which is what lets the module report on them by class and subject
  later.

You draw the layout once. Everything else hangs off it.

## Quick start

1. Go to **Learn > Seating Plan > My Lessons**. You'll see today's timetable.
2. Pick a lesson and click **Open Room**. If nobody has drawn this room yet, you'll land in
   Furniture mode with an empty grid.
3. Drag chairs and desks from the palette on the left into the room. Click a piece to rotate it.
   Click **Auto-Face** to turn every chair towards its desk.
4. Click **Save**.
5. Switch to **Seating** along the top. Your students appear down the side. Drag each one onto a
   chair.
6. Click **Save**.
7. Switch to **Register** and click each student to mark them, then click **Save Attendance**.

That register is now in Gibbon proper — check the student's profile or any attendance report.

## My Lessons

**Learn > Seating Plan > My Lessons** is the way in. It shows your timetable for a chosen day, one
row per lesson, and tells you at a glance:

| Column | What it tells you |
|---|---|
| **Period** | The period name and its times |
| **Class** | The class, plus a note if other classes share the room at the same time, and whether you're covering |
| **Room** | The room, and whether it's been moved for the day |
| **Layout** | Whether anybody has drawn this room yet |
| **Attendance** | Grey question mark for not started, red for partly done, green for complete |

Where several classes are timetabled into the same room at the same time — a split group, say —
they appear as **one row**, not several, because they're one room full of students.

If today has no lessons (a weekend, or a holiday), the page falls back to the most recent day that
did, rather than opening on an empty table. Picking a date yourself always shows that date, empty
or not.

The same table appears on your **Staff Dashboard** and inside a **Lesson Plan**, so you can reach a
room without going through the menu.

## The room screen

The room opens full screen. Along the top are the modes available for that room:

| Mode | What it's for |
|---|---|
| **Furniture** | Draw or rearrange the room |
| **Seating** | Put students on chairs |
| **Register** | Take attendance |
| **Picker** | Choose a student at random |
| **Rewards** | Record rewards and sanctions |
| **Room Exits** | Log a student out of the room and back |

Furniture is always available. The rest need a lesson (so the module knows which students) and a
saved layout (so there are chairs to sit on) — if they're missing, those modes simply aren't shown
rather than appearing greyed out.

The back arrow returns you to My Lessons if you came from a lesson, or to Room Layouts if you came
from there.

## Furniture: drawing a room

Drag a piece from the palette into the room, then drag it around. The pieces available are Chair,
Single Desk, Double Desk, Teacher Desk, Benching, Computer, Board, Display Screen, Door, Wall and
Cupboard.

| Action | How |
|---|---|
| Rotate | Click a piece, or select it and press **R** |
| Mirror a door | Right-click it, or select it and click **Mirror** |
| Resize | Drag the handle on a resizable piece (walls, benching, boards) |
| Delete | Select it and press **Delete**, or click **Delete** |
| Select several | Ctrl-click, or drag a box round them |
| Nudge precisely | Hold **Shift** while dragging to snap to whole squares |
| Save | **Save**, or **Ctrl+S** |

**Auto-Face** turns every chair to face its desk, and every computer to face its chair or away from
the wall behind it. It's the quickest way to tidy a room you've dragged together roughly.

The room's size in grid squares is shown at the top and can be changed there. One square is about
half a metre. If you shrink a room so that furniture no longer fits, the module tells you which
piece is in the way rather than quietly moving or dropping it.

## Seating: putting students in it

Your students appear as tiles with their photo and first name. Drag a tile onto a chair to seat
them.

| What you do | What happens |
|---|---|
| Drop on an empty chair | They're seated there |
| Drop on an occupied chair | The two students swap places |
| Drop between two chairs | Nothing — put them clearly on one of them |
| Drop on open floor | They stay where you put them for now, but this is **not** saved — it's how you unseat somebody |

Only students actually on a chair are saved. The furniture is shown underneath as a read-only
backdrop, so you can see the room without being able to disturb it.

Where two students share a first name, both get a surname initial, so you're never guessing which
is which.

## Badges

In Seating mode, **Badges** lets you put up to four pieces of information in the corners of every
student tile. Drag a badge into a corner; drag it out to remove it.

Available badges are the five Gibbon alert types (Individual Needs, Medical, and so on), plus
**House**, **Form Group**, **Year Group**, **Target** grade, **Current Grade** and **CAT4**.

Badge choices are **yours**, not the plan's — set them once and they apply to every room you open.
A form tutor and a subject teacher can each show different things about the same class.

Badges only appear where the underlying data exists. If your school doesn't record house colours,
or hasn't imported CAT4 scores, those badges simply don't show.

## Register: taking attendance

Click a student to mark them. Clicking again cycles forward through your school's attendance codes;
right-click steps back. A colour wash covers the tile with the code's letter and name.

Nothing is written until you click **Save Attendance**. A red border means "not recorded for this
period yet"; green means it is. The two signals are independent on purpose — a student showing a
mark with a red border has been marked before, but not for *this* lesson, so they still need saving.

The rows this writes are ordinary `gibbonAttendanceLogPerson` records, exactly as the Attendance
module would write. Every existing attendance report and the student's own profile pick them up.
For a double period, both periods are marked in one go.

A student enrolled in **more than one** of the classes sharing the room can't be marked here — the
module won't guess which class the mark belongs to. Their tile is shown but not clickable, and they
should be marked through the Attendance module instead.

Taking a register needs your school's own Attendance permission, not just access to this module.

## Picker: choosing a student

Picker is a cold-call tool. The moment you open it, a yellow highlight starts hopping across the
seated students. Tap the room to choose: the highlight narrows, slows like a wheel losing momentum,
and settles on one student, held large on screen. Tap again to start it running.

Which third of the room you tap decides which ability band it draws from — left for lower, middle,
right for higher, ranked by CAT4 Mean SAS. Students with no CAT score rank in the middle rather than
being left out. So that way you can target your questioning.

If fewer than three students in the room have a CAT score, there's nothing meaningful to rank on, so
the bands are dropped and a tap anywhere draws from the whole room.

**It works through the class rather than picking at random.** Everybody in a band is asked before
anybody is asked twice, and the same student is never chosen twice running.

## Rewards and sanctions

Arm **Reward** or **Sanction** at the top, then click students. The armed button turns a solid
colour, and the status bar says which one is armed and what a click will do.

| Action | What it does |
|---|---|
| **Click** a student | Adds one of the armed type |
| **Right-click** a student | Takes one back off, stopping at zero |

A running count for the lesson shows in the corner of each tile — green bottom-left for rewards,
red bottom-right for sanctions. Nothing shows when the count is zero.

Each click saves immediately; there's no Save button in this mode.

By default these stay inside Seating Plan and appear in its own [Reports](#reports). If you switch
on **Behaviour Settings**, they can also write records into Gibbon's Behaviour module once a student
reaches a threshold you choose — see [Settings](#settings).

Be careful - taking a point back never removes a Behaviour record that has already been written (if
it has been configured to write to Gibbon's Behaviour module), and putting the
point back never writes a second one for the same threshold. If a Behaviour record needs removing,
that's done in Gibbon's Manage Behaviour Records.

## Room exits

Click a student to mark them out of the room. Their tile shows how long they've been gone, ticking
as you watch. Click again to mark them back in.

There's no reason field and no Save button — one click out, one click back. Whether a click marks
somebody out or back in is worked out from the record, not from what the screen last showed, so two
teachers in the same room can't get out of step.

## Using a colleague's layout

You don't have to draw a room somebody has already drawn.

When you open a room, the module uses **your own** layout for it if you have one. If you don't, it
uses the most recently updated layout anyone has shared for that room.

You can rearrange a colleague's shared layout freely. **Saving makes your own copy** — their
drawing is never changed. The room tells you whose layout you're looking at before you start, so
the copy is never a surprise. From then on, that copy is yours and saves normally.

If the person you copied from later improves their layout, your copy is tagged **Newer version
available** in Room Layouts, and the room says so when you open it. Choosing **See What Changed**
shows both rooms side by side — furniture you'd lose outlined in red, furniture you'd gain in
green — and you can take their version or keep yours.

Taking their version keeps your layout, its name and its seating plans. Students already seated are
moved to the nearest free chair; anyone with no chair left needs seating again.

Renaming, unsharing and deleting a layout stay with whoever owns it. **Duplicate** is there if you
want a second copy of your own.

## Importing and exporting layouts

**Room Layouts > Import & Export** moves layouts between rooms, colleagues or schools as a JSON
file.

**Export** any layouts you can see — your own, and any a colleague has shared — as a single file.
The file holds the room size, the furniture, and the names of the layout and its room. It holds no
students, no seating plans, no register and no owner. There is nothing in it that identifies
anybody, so it's safe to email.

**Import** brings them back, into a room you choose. The room is always chosen on the form, because
the file remembers its room by name only and that means nothing in another school's Gibbon.

Importing always creates a new layout belonging to you. It never changes or replaces one that's
already there, so importing the same file twice gives you two copies. A file that isn't a layout
export, is from a newer version of the module, or contains furniture that won't fit its room, is
refused with a message saying which — and leaves nothing behind.

## Reports

**Learn > Seating Plan > Reports** covers rewards and sanctions on one page and room exits on
another, with a link between them.

Filter by date range, student, year group, form group, subject, class, recording teacher and room.
Then choose what to **group by** — student, class, subject, year group, form group, recording
teacher, room or date — and both the chart and the table follow.

Student rows carry a circular photo and link through to that student's own page, which lists every
point and every exit in date order.

Room exit reporting shows how often and how long: a class with five short exits and a class with
one very long one are different problems. An exit still open contributes to the count but not to the
time, so a forgotten click doesn't quietly inflate the totals.

What you can see depends on your permission: **Reports_all** covers every student, **Reports_my**
only students in classes you teach, plus anything you recorded yourself.

## Correcting a record

Most mistakes are best fixed in the lesson, by right-clicking in Rewards mode. For anything that
gets away — and for room exits, which have no in-lesson correction — an administrator can amend the
record afterwards.

With the **Manage Records** permission, each row on a student's page gains **Correct** and
**Delete**:

| Record | What you can correct |
|---|---|
| Rewards and sanctions | The two counts for that lesson |
| Room exit | The date, the time out and the time back — clearing the time back reopens the exit |

Every change is written to Gibbon's system log with the old and new values, so an amendment is
always attributable.

Correcting a tally does **not** touch any Behaviour record already written for that lesson. Those
belong to the Behaviour module, and the page says so with a pointer to Manage Behaviour Records.

## Settings

**Learn > Seating Plan > Badge Settings** sets the colour shown behind each House badge. If your
school has no houses configured, the page says so and the badge simply never appears.

**Learn > Seating Plan > Behaviour Settings** decides whether rewards and sanctions also become
records in Gibbon's Behaviour module. This is **off** by default — a quick tap during a lesson
shouldn't become a permanent behaviour record unless your school wants it to.

With it switched on:

| Setting | What it means |
|---|---|
| **Threshold** | How many points of one type a student needs **in one lesson** before the first Behaviour record is written. Default 3 |
| **Then Every** | One more record every this many points after the threshold. Default 1. Set to **0** for a single record per lesson |
| **Descriptor**, **Level**, **Incident** | What gets written on the record, set separately for rewards and sanctions |

Descriptors and levels are read from the Behaviour module's own settings, and only offered here if
Behaviour has them switched on — so this page can never store one Behaviour would reject.

Rewards mode deliberately offers only two buttons, so every record of one type carries the same
descriptor. Anyone wanting to choose per incident still has the Behaviour module itself.

## Permissions

| Action | What it allows | Default roles |
|---|---|---|
| **My Lessons** | Open a room from your timetable, and use every mode in it | Administrator, Teacher |
| **Room Layouts** | Draw, share, duplicate, import and export layouts | Administrator, Teacher |
| **Reports_all** | Report on every student | Administrator |
| **Reports_my** | Report on students in your own classes, plus anything you recorded | Teacher |
| **Manage Records** | Correct or delete a student's reward, sanction or room exit record | Administrator |
| **Badge Settings** | Set house badge colours | Administrator |
| **Behaviour Settings** | Decide whether and how points reach the Behaviour module | Administrator |

Taking a register additionally needs your school's own **Attendance** permission — this module
deliberately doesn't invent a second way to be allowed to mark attendance.

As with any Gibbon module, these can be granted to other roles from **Admin > User Admin > Roles &
Permissions** — a Leadership Team role, for example, might reasonably be given Reports_all and
Manage Records without full Administrator access.

## Troubleshooting

**Seating, Register, Picker, Rewards or Room Exits isn't showing.**
Those modes need two things: a lesson (so the module knows which students) and a saved layout for
the room (so there are chairs). Draw the room in Furniture mode and save it, and they'll appear. If
you reached the room from Room Layouts rather than My Lessons, there's no lesson, so only Furniture
is offered.

**A student's tile can't be clicked in Register mode.**
They're enrolled in more than one of the classes sharing that room, so the module can't tell which
class the mark belongs to and won't guess. Mark them through the Attendance module instead.

**"No attendance code is available for your role."**
Your role doesn't hold any active attendance code. That's configured in Attendance, not here.

**My Lessons is empty.**
Either the day genuinely has no lessons for you, or your lessons have no room on the timetable. The
module can only show a room it can find in Timetable Admin.

**I saved a colleague's layout and now there are two.**
That's intended — saving somebody else's layout makes your own copy rather than changing theirs.
Yours is the one the room will open from now on. Delete it if you'd rather go back to using theirs.

**The Picker keeps choosing from the whole room however I tap.**
Fewer than three students in that room have a CAT4 Mean SAS score, so there's nothing to rank on
and the ability bands are deliberately dropped.

**A Behaviour record appeared that I didn't expect.**
Check **Behaviour Settings** — if writing is switched on, points reaching the threshold produce
records automatically. Turning it off stops new ones; existing records are removed in Manage
Behaviour Records.

**An import was refused.**
The message says why: the file isn't a layout export, it's from a newer version of the module, or a
piece of furniture won't fit the room it's going into. Nothing is created by a refused import.

## What this module does not do

To keep the module focused, the following are intentionally not included:

- Importing data from any other seating plan application
- Google Classroom or Microsoft Teams integration
- Printing a seating plan or a register
- Booking rooms, or resolving timetable clashes
- Emailing or notifying anybody about a reward, sanction or room exit
- Creating a reward or sanction record for a lesson that never had one
- Changing the Behaviour module's own records — that's done in Behaviour

## Support

Author: Steve Gillott. For issues or questions, please open an issue in this repository.
