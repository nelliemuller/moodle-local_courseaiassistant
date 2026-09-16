<?php

// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.


/**
 * English language strings for AI Course Assistant.
 *
 * @package   local_courseaiassistant
 * @copyright 2026 Nellie Deutsch
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'AI Course Assistant';
$string['courseaiassistant'] = 'AI Course Assistant';
$string['siteadministrator'] = 'Site administrator';

$string['missingapikey'] = 'The Gemini API key has not been configured.';
$string['missinggeminikey'] = 'The Gemini API key has not been configured.';
$string['missingopenaikey'] = 'The OpenAI API key has not been configured.';
$string['invalidairesponse'] = 'The configured AI provider returned an invalid response.';
$string['emptyairesponse'] = 'The configured AI provider returned an empty response.';
$string['providererror'] = 'I could not answer that course question right now. Please try again.';
$string['invalidresponse'] = 'Gemini returned an invalid response.';
$string['apierror'] = 'Gemini API error.';
$string['emptyresponse'] = 'Gemini returned an empty response.';
$string['invalidrequest'] = 'Invalid request.';
$string['invalidsessionkey'] = 'Invalid session key.';
$string['questionrequired'] = 'The course ID and question are required.';
$string['questiontoolong'] = 'The question is too long. Please shorten it and try again.';
$string['historyunavailable'] = 'Chat history is unavailable.';
$string['historyrequestfailed'] = 'Chat history request failed.';
$string['conversationdefaulttitle'] = 'Course conversation';
$string['nomessagestosave'] = 'No messages to save.';
$string['unknownaction'] = 'Unknown action.';

$string['setting:geminiapikey'] = 'Gemini API key';
$string['setting:geminiapikey_desc'] = 'Enter the Gemini API key used by this Moodle site. The key is stored in Moodle configuration and is never sent to the browser.';
$string['setting:model'] = 'Gemini model';
$string['setting:model_desc'] = 'Enter the Gemini model identifier used for course assistant responses.';
$string['setting:usethemecolor'] = 'Use Moodle theme color';
$string['setting:usethemecolor_desc'] = 'Automatically inherit the active Moodle theme brand color. Recommended.';
$string['setting:customprimary'] = 'Custom primary color';
$string['setting:customprimary_desc'] = 'Used only when Moodle theme color inheritance is disabled.';

$string['welcomeuser'] = 'Hello, {$a}!';
$string['welcomecourse'] = 'Welcome to {$a}.';
$string['askanything'] = 'Ask me anything about the course.';
$string['assistantsubtitle'] = 'Your intelligent course companion';
$string['downloadcurrentchat'] = 'Download current chat';
$string['conversationhistory'] = 'Conversation history';
$string['collapsechat'] = 'Collapse chat';
$string['openchat'] = 'Open chat';
$string['suggestedquestions'] = 'Suggested questions';
$string['starthere'] = 'Start Here';
$string['whatsnext'] = "What's Next?";
$string['explain'] = 'Explain';
$string['summarize'] = 'Summarize';
$string['promptstarthere'] = 'Where do I start?';
$string['promptwhatsnext'] = "What's next?";
$string['promptexplain'] = 'Explain or clarify what we were just discussing. Focus on my most recent question and your answer.';
$string['promptsummarize'] = 'Summarize what we were just discussing or the course item or topic I most recently asked about. Give me the main points.';
$string['inputplaceholder'] = 'Ask about this course...';
$string['inputlabel'] = 'Ask the AI Course Assistant';
$string['speakquestion'] = 'Speak your question';
$string['send'] = 'Send';
$string['newchat'] = 'New Chat';
$string['closehistory'] = 'Close history';
$string['deleteallconversations'] = 'Delete all saved conversations';
$string['deleteallconfirm'] = 'Delete all saved conversations for this course?';
$string['userprofilepicture'] = 'Your Moodle profile picture';
$string['moodleuser'] = 'Moodle user';
$string['readresponse'] = 'Read this response aloud';
$string['readaloud'] = 'Read aloud';
$string['stopreading'] = 'Stop reading';
$string['loadingconversations'] = 'Loading conversations...';
$string['nopreviousconversations'] = 'No previous conversations yet.';
$string['downloadtxt'] = 'Download as TXT';
$string['downloadhtml'] = 'Download as HTML';
$string['renameconversation'] = 'Rename conversation';
$string['renamethisconversation'] = 'Rename this conversation';
$string['deletethisconversation'] = 'Delete this conversation';
$string['askbeforedownload'] = 'Ask at least one question before downloading the chat.';
$string['downloadfailed'] = 'The chat could not be downloaded.';
$string['speechnotsupported'] = 'Speech input is not supported in this browser.';
$string['speechunderstandfailed'] = 'I could not understand the speech. Please try again.';

$string['privacy:metadata:conversation'] = 'The AI Course Assistant stores saved conversations belonging to Moodle users.';
$string['privacy:metadata:conversation:userid'] = 'The ID of the Moodle user who owns the conversation.';
$string['privacy:metadata:conversation:courseid'] = 'The Moodle course in which the conversation occurred.';
$string['privacy:metadata:conversation:title'] = 'The title assigned to the saved conversation.';
$string['privacy:metadata:conversation:timecreated'] = 'The time the conversation was created.';
$string['privacy:metadata:conversation:timemodified'] = 'The time the conversation was last modified.';
$string['privacy:metadata:message'] = 'Messages stored within an AI Course Assistant conversation.';
$string['privacy:metadata:message:conversationid'] = 'The saved conversation containing the message.';
$string['privacy:metadata:message:role'] = 'Whether the message was created by the user or the AI assistant.';
$string['privacy:metadata:message:message'] = 'The content of the conversation message.';
$string['privacy:metadata:message:contextjson'] = 'Structured course context saved with the message.';
$string['privacy:metadata:message:timecreated'] = 'The time the message was created.';
$string['privacy:metadata:gemini'] = 'The AI Course Assistant sends data needed to answer a course question to the configured Google Gemini service.';
$string['privacy:metadata:gemini:question'] = 'The user question sent to Gemini.';
$string['privacy:metadata:gemini:history'] = 'Recent course-assistant conversation context sent to Gemini.';
$string['privacy:metadata:gemini:coursecontext'] = 'Relevant visible Moodle course content, instructions, completion/badge criteria, links, and a specifically requested forum discussion/post when needed to answer the question.';
$string['privacy:metadata:gemini:rolecontext'] = 'Permission-aware role and capability context used to constrain the response.';
$string['privacy:metadata:gemini:progress'] = 'The current user course-completion state used to provide progress-aware guidance.';

$string['setting:identityheading'] = 'Assistant identity and availability';
$string['setting:identityheading_desc'] = 'Choose the assistant name and where it is available.';
$string['setting:enabled'] = 'Enable AI Course Assistant';
$string['setting:enabled_desc'] = 'Display the assistant for signed-in users who have permission to use it.';
$string['setting:assistantname'] = 'Default assistant display name';
$string['setting:assistantname_desc'] = 'Enter the name participants will see. This does not change the Moodle plugin component name.';
$string['setting:allowdashboard'] = 'Allow Dashboard access';
$string['setting:allowdashboard_desc'] = 'Allow access from the Moodle Dashboard. The Dashboard view lists the user’s courses and opens the selected course assistant on a dedicated page.';
$string['setting:languageheading'] = 'Response language';
$string['setting:languageheading_desc'] = 'The interface follows Moodle language strings. This setting controls the language requested for AI-generated answers.';
$string['setting:responselanguage'] = 'Default response language behavior';
$string['setting:responselanguage_desc'] = 'Follow each user’s Moodle language, match the language used in the learner’s question, follow the site language, or force one language code.';
$string['setting:fixedlanguage'] = 'Fixed response language code';
$string['setting:fixedlanguage_desc'] = 'Used only when Fixed language is selected. Enter a Moodle-style language code such as en, es, fr, de, ar, or he.';
$string['setting:aiheading'] = 'AI provider';
$string['setting:aiheading_desc'] = 'Choose the GenAI service used for course reasoning. Provider credentials stay on the Moodle server and are never sent to the browser.';
$string['setting:aiprovider'] = 'AI provider';
$string['setting:aiprovider_desc'] = 'Choose the provider used to reason over Moodle course context. Gemini and OpenAI use the same permission-aware course comprehension layer.';
$string['provider:gemini'] = 'Google Gemini';
$string['provider:openai'] = 'OpenAI';
$string['setting:openaiapikey'] = 'OpenAI API key';
$string['setting:openaiapikey_desc'] = 'Enter the OpenAI API key used by this Moodle site. The key is stored in Moodle configuration and is never sent to the browser.';
$string['setting:openaimodel'] = 'OpenAI model';
$string['setting:openaimodel_desc'] = 'Enter an OpenAI model identifier available to your API project. The default balances reasoning quality and cost.';
$string['setting:openaireasoning'] = 'OpenAI reasoning effort';
$string['setting:openaireasoning_desc'] = 'Controls how much reasoning effort the selected OpenAI model uses for course questions. Medium is a balanced default.';
$string['reasoning:none'] = 'None';
$string['reasoning:low'] = 'Low';
$string['reasoning:medium'] = 'Medium';
$string['reasoning:high'] = 'High';
$string['reasoning:xhigh'] = 'Extra high';
$string['reasoning:max'] = 'Maximum';
$string['setting:appearanceheading'] = 'Appearance';
$string['setting:iconsource'] = 'Assistant icon';
$string['setting:iconsource_desc'] = 'Choose the icon shown in the launcher, header, welcome message, and AI replies.';
$string['iconsource:default'] = 'Default star icon';
$string['iconsource:sitelogo'] = 'Moodle site logo';
$string['iconsource:custom'] = 'Uploaded custom image';
$string['setting:assistantimage'] = 'Custom assistant image';
$string['setting:assistantimage_desc'] = 'Upload one image to use when Uploaded custom image is selected. Recommended: 512 × 512 pixels, square, transparent PNG, under 500 KB. Keep the logo centred with transparent spacing around it. Uploaded images and site logos display without a background; the default star keeps its white tile.';
$string['setting:launcherplacement'] = 'Course-page launcher placement';
$string['setting:launcherplacement_desc'] = 'Choose where the assistant launcher appears. Top right, bottom right, bottom center, bottom left, and course header open a chat on the course page. The chat can be made movable, dragged anywhere in the visible page, and restored automatically when the user follows another link in the same course. Static full-page assistant opens on a separate page with a Return to course button and is not movable. If the active theme does not provide a compatible course header, course-header placement falls back to bottom right.';
$string['launcherplacement:bottomright'] = 'Bottom right';
$string['launcherplacement:bottomcenter'] = 'Bottom center';
$string['launcherplacement:bottomleft'] = 'Bottom left';
$string['launcherplacement:courseheader'] = 'Course header button';
$string['launcherplacement:fullpage'] = 'Static full-page assistant';
$string['launcherplacement:askgemini'] = 'Top right with movable chat';
$string['popoutchat'] = 'Make chat movable';
$string['dockchat'] = 'Return chat to its placement';
$string['movechat'] = 'Drag the chat header to move it';

$string['language:user'] = 'Follow each user’s Moodle language';
$string['language:question'] = 'Match the language used in the learner’s question';
$string['language:site'] = 'Follow the Moodle site language';
$string['language:fixed'] = 'Use one fixed language';

$string['openassistant'] = 'Open {$a}';
$string['returntocourse'] = 'Return to course';
$string['openfullpage'] = 'Open full-page assistant';
$string['backtocourse'] = 'Back to course';
$string['dashboardchoosecourse'] = 'Choose a course to open {$a}.';
$string['dashboardnocourses'] = 'No available courses were found for your account.';
$string['sending'] = 'Sending...';
$string['unexpectedresponse'] = 'The assistant returned an unexpected response. Please try again.';
$string['noresponse'] = 'No response received.';
$string['serviceunavailable'] = 'Could not contact the AI service.';
$string['coursecheckfailed'] = 'I could not complete that course check because one Moodle data source returned an error. Please try again.';
$string['closeassistant'] = 'Close AI Course Assistant';
$string['instancecustomizationdisabled'] = 'Course-level customization is disabled by the site administrator. This instance uses the site defaults.';

$string['privacy:metadata:openai'] = 'When OpenAI is selected, the AI Course Assistant sends data needed to answer a course question to the configured OpenAI service.';
$string['privacy:metadata:openai:question'] = 'The user question sent to OpenAI.';
$string['privacy:metadata:openai:history'] = 'Recent course-assistant conversation context sent to OpenAI.';
$string['privacy:metadata:openai:coursecontext'] = 'Relevant visible Moodle course content, instructions, completion/badge criteria, links, and a specifically requested forum discussion/post when needed to answer the question.';
$string['privacy:metadata:openai:rolecontext'] = 'Permission-aware role and capability context used to constrain the response.';
$string['privacy:metadata:openai:progress'] = 'The current user course-completion state used to provide progress-aware guidance.';
$string['privacy:metadata:openai:safetyidentifier'] = 'A one-way pseudonymous identifier derived from the Moodle user ID for provider abuse monitoring.';

$string['openaiassistant'] = 'Open AI Assistant';

$string['copychat'] = 'Copy chat';
$string['chatcopied'] = 'Chat copied.';
$string['copyfailed'] = 'The chat could not be copied.';
$string['nothingtocopy'] = 'There is no conversation to copy yet.';
$string['clipboardunavailable'] = 'Clipboard access is unavailable in this browser.';

$string['courseaiassistant:use'] = 'Use the AI Course Assistant';
