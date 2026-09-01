# Release testing

Before publishing a release, verify the following on a non-production Moodle site with developer debugging enabled.

* Install the plugin from its ZIP on a clean supported Moodle version.
* Upgrade from the preceding public release.
* Test as a student, teacher, editing teacher, manager, and user without the use capability.
* Test Google Gemini and OpenAI separately with valid and invalid credentials.
* Test the default star, Moodle site logo, and uploaded-image options.
* Test all launcher placements with more than one Moodle theme and on mobile: bottom right, bottom center, bottom left, course-header drawer button, full-page assistant link, and Ask Gemini-style right dock with movable pop-out. Confirm the documented bottom-right fallback when no compatible course header exists.
* Open the right-docked assistant, confirm the course page remains visible beside it, pop the chat out, drag it by the dedicated top toolbar to multiple positions, use its controls and input while floating, dock it again, and confirm the conversation is preserved. Confirm pop-out is disabled on narrow screens.
* Repeat pop-out, drag, dock, and conversation-preservation testing for bottom-right, bottom-center, bottom-left, and course-header presentations.
* Confirm the fifth full-page assistant remains static and does not display a Pop out chat control.
* Test the drawer, full-page view, Dashboard access, history, rename, delete, clear, copy, and both download formats.
* Confirm course links and answers never expose content hidden from the current user.
* Confirm lists render with indentation and copied transcripts do not contain escaped Markdown.
* Test keyboard navigation, focus return, screen-reader labels, and narrow mobile layouts.
* Run Moodle Plugin CI, PHP CodeSniffer with Moodle coding standards, PHP lint, and PHPUnit privacy tests.
