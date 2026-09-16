# Moodle Marketplace listing draft

## Name

AI Course Assistant

## Short description

A course-aware AI assistant that helps Moodle users understand course content, activities, progress, and next steps.

## Full description

AI Course Assistant adds a responsive assistant drawer and a dedicated assistant page to Moodle courses. It builds answers from the course information that the signed-in user is permitted to see, helping learners navigate course requirements and helping educators understand relevant course activity.

The plugin is a standalone local plugin and does not require a block plugin. Site administrators can choose Google Gemini or OpenAI, configure the model and response language, and brand the assistant with the default star, the Moodle site logo, or an uploaded image.

## Key features

* Course-aware answers based on visible Moodle content and activities
* Capability-aware responses for learners, teachers, and managers
* Learner progress and completion guidance
* Google Gemini and OpenAI provider support
* Saved conversation history, copy, text download, and HTML download
* Optional speech input and read-aloud controls
* Responsive course drawer and dedicated full-page view
* Configurable name, colour, language, and assistant image
* Moodle Privacy API support

## Requirements

* Moodle 5.0, 5.1, or 5.2
* A supported PHP version for the selected Moodle release
* An administrator-supplied Google Gemini or OpenAI API key

AI-provider accounts, usage allowances, and API charges are not included.

## Data disclosure

To produce a response, the plugin may send the user's question, recent conversation context, relevant course information visible to that user, role and capability context, and progress information to the AI provider selected by the site administrator. OpenAI requests also include a one-way pseudonymous safety identifier. Saved conversations remain in Moodle and are covered by Moodle's Privacy API.

## Before submission

Replace the following entries in the Marketplace form with public URLs:

* Source-code repository
* Issue tracker
* User and administrator documentation

Upload screenshots showing the closed launcher, open course drawer, assistant settings, icon choices, and a mobile layout.

