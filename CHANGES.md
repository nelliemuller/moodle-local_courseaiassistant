# Change log

## 2.1.0 RC25

- Restored the actual separate browser pop-out for every non-static placement.
- Created the pop-out with native noopener isolation so Safari course navigation cannot close it.
- Preserved Dock chat through same-site storage even after the originating course page changes.

## 2.1.0 RC24

- Persisted the movable assistant state across full Moodle page loads with same-site local storage.
- Saved the latest open mode and position again when the current course page exits.
- Restored the active conversation across course links and tabs.

## 2.1.0 RC23

- Replaced the separate browser pop-out with an in-page movable assistant for every non-static placement.
- Restored the open movable assistant, its position, and its active conversation after navigation within the same course.
- Kept the Moodle course at full width while the movable assistant is open.

## 2.1.0 RC22

- Detached persistent pop-outs from their originating course page so Safari does not close them during navigation.
- Used cross-window browser storage to return a detached pop-out to the course page with Dock chat.

## 2.1.0 RC21

- Prevented the course-page hook from injecting a duplicate assistant into the pop-out page.
- Isolated every persistent browser pop-out from unrelated site-wide floating controls.
- Made the assistant fill its pop-out cleanly and responsively in Safari and Chrome.
- Kept the underlying course at full width instead of shifting it like a Moodle block.

## 2.1.0 RC20

- Replaced the page-bound movable panel with a persistent browser pop-out window.
- Added a Dock chat control that returns focus to the course and reopens the docked assistant.

## 2.1.0 RC19

- Preserved the right-docked assistant and shifted course layout while keeping Moodle's block drawer accessible.

## 2.1.0 RC18

- Moved Top right with pop out left by default so Moodle's block drawer arrow remains accessible.

## 2.1.0 RC17

- Kept Top right with pop out clear of Moodle's right block drawer and its controls.

## 2.1.0 RC16

- Positioned Top right with pop out beneath Moodle's course navigation instead of overlapping it.

## 2.1.0 RC15

* Updated the placement explanation to match the administrator menu and removed the repeated default label.

## 2.1.0 RC14

* Placed Top right with pop out first in the administrator placement menu.

## 2.1.0 RC13

* Simplified the administrator placement menu to short placement names.
* Renamed the right-docked placement to Top right.

## 2.1.0 RC12

* Positioned the right-docked chat launcher at the top right of course pages.

## 2.1.0 RC11

* Separated the right-docked launcher from the Moodle course-header placement.
* Right-docked chat now opens from its own fixed launcher and docks against the right side of the course page.

## 2.1.0 RC10

* Removed the separate white utility toolbar above the assistant.
* Moved pop-out and close controls into the existing blue assistant header.
* Removed the assistant subtitle and made the unified blue header the drag surface for the movable window.

## 2.1.0 RC9

* Removed numbering from administrator placement labels and clarified each placement in plain language.
* Refined the docked and pop-out assistant proportions with a compact control toolbar.
* Raised the bottom-left launcher so it remains visible when another fixed site control occupies the lower-left corner.

## 2.1.0

* Added administrator-selected launcher placement: bottom right, bottom center, bottom left, course-header drawer button, full-page assistant link, or Ask Gemini-style right dock with movable pop-out, with bottom right as the default.
* Added an Ask Gemini-style right dock and dedicated pop-out toolbar that lets users drag the assistant anywhere within the browser viewport and dock it again without losing the conversation.
* Added movable pop-out chat to bottom-right, bottom-center, bottom-left, and course-header presentations while preserving the fifth full-page assistant as a static view.
* Added public-facing administrator descriptions explaining the initial position, course-page behavior, pop-out availability, and Return to course behavior of every placement.
* Added administrator guidance for a square 512 × 512 transparent custom image under 500 KB.
* Display site and custom logos without the default star's white background tile.
* Standalone local plugin with no block-plugin dependency.
* Course-aware, role-aware guidance for enrolled Moodle users.
* Google Gemini and OpenAI provider support with administrator-supplied API keys.
* Conversation history, copy, text and HTML download, speech input, and read-aloud controls.
* Configurable assistant name, colours, response language, and assistant image.
* Moodle Privacy API implementation for stored conversations and external AI processing.
* Security hardening for course capability checks, session keys, and user-owned conversation access.
