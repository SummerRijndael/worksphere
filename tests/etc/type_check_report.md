# Type Check Report

## Critical (App-Breaking Errors / Syntax / Missing Dependencies)
[Vue] Cannot find name/variable: resources/js/components/tasks/TaskDetailModal.vue(149,15): error TS2304: Cannot find name 'Pause'.
[Vue] Cannot find name/variable: resources/js/views/call/CallApp.vue(1831,32): error TS2304: Cannot find name 'toast'.
[Vue] Cannot find name/variable: resources/js/views/call/CallApp.vue(1832,41): error TS2304: Cannot find name 'toast'.
[Vue] Cannot find name/variable: resources/js/views/call/CallApp.vue(1833,66): error TS2304: Cannot find name 'toast'.


## High (Type Mismatches / Missing Properties / Potentially Null Access)
[Vue] Property does not exist: resources/js/call.ts(16,8): error TS2339: Property 'EventEmitter' does not exist on type 'Window & typeof globalThis'.
[Vue] Property does not exist: resources/js/components/invoices/RecordPaymentModal.vue(119,40): error TS2339: Property 'prefix' does not exist on type '{}'.
[Vue] Property does not exist: resources/js/components/minichat/MiniChatMessageBubble.vue(164,64): error TS2339: Property 'chat_id' does not exist on type 'Message'.
[Vue] Property does not exist: resources/js/components/minichat/MiniChatMessageBubble.vue(169,45): error TS2339: Property 'chat_id' does not exist on type 'Message'.
[Vue] Property does not exist: resources/js/components/settings/BlockedUrlManager.vue(31,39): error TS2339: Property 'data' does not exist on type 'BlockedUrl[]'.
[Vue] Property does not exist: resources/js/components/settings/BlockedUrlManager.vue(35,40): error TS2339: Property 'current_page' does not exist on type 'BlockedUrl[]'.
[Vue] Property does not exist: resources/js/components/settings/BlockedUrlManager.vue(36,39): error TS2339: Property 'last_page' does not exist on type 'BlockedUrl[]'.
[Vue] Type assignment/argument mismatch: resources/js/components/tasks/TaskBoard.vue(231,46): error TS2345: Argument of type '"quick-assign"' is not assignable to parameter of type '"task-click"'.
[Vue] Type assignment/argument mismatch: resources/js/components/tasks/TaskFormModal.vue(451,13): error TS2322: Type '{ title: string; description: string; status: string; priority: number; due_date: string; assigned_to: string; estimated_hours: number; checklist: never[]; save_as_template: false; }' is not assignable to type '{ title: string; description: string; status: string; priority: number; due_date: string; assigned_to: string; qa_user_id: string; estimated_hours: number; checklist: any[]; save_as_template: boolean; } | { ...; }'.
[Vue] Property does not exist: resources/js/components/tasks/TaskList.vue(435,42): error TS2339: Property 'can' does not exist on type 'Task'.
[Vue] Property does not exist: resources/js/components/tasks/TaskList.vue(439,42): error TS2339: Property 'can' does not exist on type 'Task'.
[Vue] Property does not exist: resources/js/components/tasks/TaskList.vue(444,42): error TS2339: Property 'can' does not exist on type 'Task'.
[Vue] Property does not exist: resources/js/components/tasks/TaskList.vue(479,42): error TS2339: Property 'can' does not exist on type 'Task'.
[Vue] Property does not exist: resources/js/components/tasks/TaskList.vue(483,42): error TS2339: Property 'can' does not exist on type 'Task'.
[Vue] Property does not exist: resources/js/components/tasks/TaskList.vue(488,42): error TS2339: Property 'can' does not exist on type 'Task'.
...and 828 more

## Medium (Implicit Any / Other TS and PHP Analysis Errors)
[Vue] Other TS error: resources/js/components/faq/FaqPreviewModal.vue(2,10): error TS2440: Import declaration conflicts with local declaration of 'defineProps'.
[Vue] Other TS error: resources/js/components/faq/FaqPreviewModal.vue(2,23): error TS2440: Import declaration conflicts with local declaration of 'defineEmits'.
[Vue] Implicit any: resources/js/components/projects/ProjectGanttChart.vue(3,19): error TS7016: Could not find a declaration file for module 'frappe-gantt'. '/home/ryann/projects/WorkSphere/node_modules/frappe-gantt/dist/frappe-gantt.es.js' implicitly has an 'any' type.
[Vue] Other TS error: resources/js/components/tasks/TaskBoard.vue(9,5): error TS2300: Duplicate identifier 'public_id'.
[Vue] Other TS error: resources/js/components/tasks/TaskBoard.vue(33,5): error TS2300: Duplicate identifier 'public_id'.
[Vue] Other TS error: resources/js/components/tasks/TaskList.vue(2,10): error TS2440: Import declaration conflicts with local declaration of 'defineProps'.
[Vue] Other TS error: resources/js/components/tasks/TaskList.vue(2,23): error TS2440: Import declaration conflicts with local declaration of 'defineEmits'.
[Vue] Implicit any: resources/js/components/tools/TeamCalendar.vue(91,30): error TS7006: Parameter 'start' implicitly has an 'any' type.
[Vue] Implicit any: resources/js/components/tools/TeamCalendar.vue(91,37): error TS7006: Parameter 'end' implicitly has an 'any' type.
[Vue] Implicit any: resources/js/components/tools/TeamCalendar.vue(102,45): error TS7006: Parameter 'h' implicitly has an 'any' type.
[Vue] Implicit any: resources/js/components/tools/TeamCalendar.vue(119,24): error TS7006: Parameter 'code' implicitly has an 'any' type.
[Vue] Other TS error: resources/js/components/tools/TeamCalendar.vue(129,22): error TS18046: 'event' is of type 'unknown'.
[Vue] Other TS error: resources/js/composables/useEmailSanitization.ts(52,37): error TS2550: Property 'replaceAll' does not exist on type 'string'. Do you need to change your target library? Try changing the 'lib' compiler option to 'es2021' or later.
[Vue] Other TS error: resources/js/composables/useEmailSanitization.ts(57,37): error TS2550: Property 'replaceAll' does not exist on type 'string'. Do you need to change your target library? Try changing the 'lib' compiler option to 'es2021' or later.
[Vue] Other TS error: resources/js/composables/useRecaptcha.ts(55,32): error TS2774: This condition will always return true since this function is always defined. Did you mean to call it instead?
...and 3082 more

## Low (Unused Variables / Unnecessary Code / Strict Comparisons)
[Vue] Unused variable/import: resources/js/components/common/CookieConsent.vue(3,10): error TS6133: 'X' is declared but its value is never read.
[Vue] Unused variable/import: resources/js/components/communication/DialerModal.vue(3,44): error TS6133: 'ShieldCheck' is declared but its value is never read.
[Vue] Unused variable/import: resources/js/components/communication/DialerModal.vue(3,57): error TS6133: 'Info' is declared but its value is never read.
[Vue] Unused variable/import: resources/js/components/communication/DialerModal.vue(47,7): error TS6133: 'isMuted' is declared but its value is never read.
[Vue] Unused variable/import: resources/js/components/communication/DialerModal.vue(48,7): error TS6133: 'isSpeakerOn' is declared but its value is never read.
[Vue] Unused variable/import: resources/js/components/communication/DialerModal.vue(51,7): error TS6133: 'formattedNumber' is declared but its value is never read.
[Vue] Unused variable/import: resources/js/components/invoices/RecordPaymentModal.vue(2,25): error TS6133: 'computed' is declared but its value is never read.
[Vue] Unused variable/import: resources/js/components/layout/AppSidebar.vue(33,5): error TS6133: 'ChevronLeft' is declared but its value is never read.
[Vue] Unused variable/import: resources/js/components/layout/AppSidebar.vue(34,5): error TS6133: 'ChevronRight' is declared but its value is never read.
[Vue] Unused variable/import: resources/js/components/minichat/MiniChatPanel.vue(4,9): error TS6133: 'formatDate' is declared but its value is never read.
...and 203 more
