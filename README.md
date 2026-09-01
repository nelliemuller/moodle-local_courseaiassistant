# AI Course Assistant

AI Course Assistant is a standalone Moodle local plugin that provides permission-aware, course-aware guidance inside Moodle courses. It uses a Google Gemini or OpenAI API account configured by the site administrator and reads only Moodle information available to the current user. It has no dependency on a block plugin.

## Features

* Course content, activity, completion, and progress awareness
* Role and capability aware responses
* Conversation history, copy, download, speech input, and read aloud controls
* Course-page drawer and dedicated full-page assistant
* Configurable assistant name, color, response language, provider, and model
* Configurable assistant identity using the default star, the Moodle site logo, or one uploaded image
* Administrator-selected launcher placement: top right, bottom right, bottom center, bottom left, course-header button, or static full-page assistant link
* Movable in-page chat for top-right, bottom-right, bottom-center, bottom-left, and course-header presentations
* Saved open state and position, allowing the movable chat to restore automatically during navigation within the same course

The administrator placement choices are described directly in the settings. All placements except Static full-page assistant keep users on the course page and provide a movable chat. Static full-page assistant opens on a separate page with a Return to course control and is not movable.
* Visible Unicode bullets and clean numbered lists in responses and downloads
* Privacy provider and Moodle capability controls

## Requirements

* Moodle 5.0 or later
* PHP version supported by the installed Moodle release
* A Google Gemini or OpenAI API key supplied by the site administrator

## Installation

Install the ZIP through Site administration > Plugins > Install plugins, or copy the `courseaiassistant` directory to `local/courseaiassistant`. Then visit Site administration > Notifications to complete installation or upgrade.

Configure the plugin at Site administration > Plugins > Local plugins > AI Course Assistant. Select an AI provider, enter the corresponding API key and model identifier, choose the launcher placement, and save the settings. Bottom right is the default. Course-header placements fall back to the bottom right when the active theme has no compatible header container. No command-line build or dependency installation is required.

## AI service costs

The Moodle site owner supplies the API credentials and pays the selected AI provider directly. No API key, hosted AI service, or usage allowance is included with the plugin. Provider availability, terms, data handling, and charges are controlled by Google or OpenAI.

## Privacy and responsible use

Questions, recent conversation context, relevant visible course content, role and capability context, and progress information may be sent to the configured AI provider to generate answers. When OpenAI is selected, the request also contains a one-way pseudonymous safety identifier. API credentials remain on the Moodle server.

Saved conversations are stored in Moodle and can be exported or deleted through Moodle's Privacy API. Users can also delete their saved assistant history in the interface. Site administrators must configure and use the plugin in accordance with their institution's privacy, retention, AI, and acceptable-use policies.

## Permissions

The `local/courseaiassistant:use` capability controls access at course context. It is allowed by default for students, teachers, editing teachers, and managers. Every page and data endpoint verifies both course access and this capability.

## Accessibility

The interface provides keyboard-operable controls, accessible labels, focus handling, an ARIA live conversation log, and responsive layouts. Speech input depends on browser support and is optional.

## Support and development

* Source code and documentation: https://github.com/nelliemuller/moodle-local_courseaiassistant
* Issue tracker: https://github.com/nelliemuller/moodle-local_courseaiassistant/issues

## License

GNU GPL v3 or later.
