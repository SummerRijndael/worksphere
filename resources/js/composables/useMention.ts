import Tribute from "tributejs";
import { onUnmounted, ref, type Ref } from "vue";
import axios from "axios";
import { debounce } from "lodash";

export interface MentionOptions {
    trigger?: string;
    iframe?: any;
    selectClass?: string;
    containerClass?: string;
    itemClass?: string;
    // Callback to fetch users
    fetchUsers?: (query: string) => Promise<any[]>;
    // Callback when an item is selected
    onSelect?: (item: any) => void;
}

export function useMention(textareaRef: Ref<HTMLTextAreaElement | null>, chatId: Ref<string | undefined> | string | undefined, options: MentionOptions = {}) {
    const tribute = ref<Tribute<any> | null>(null);

    // Debounced search function
    const debouncedSearch = debounce(async (text: string, cb: (users: any[]) => void) => {
        try {
            const currentChatId = typeof chatId === 'string' ? chatId : chatId?.value;
            const response = await axios.get(`/api/chat/people/search`, {
                params: { 
                    q: text,
                    chat_id: currentChatId
                }
            });
            cb(response.data.data);
        } catch (e) {
            console.error("Error fetching mentions:", e);
            cb([]);
        }
    }, 300);

    const attach = () => {
        if (!textareaRef.value) return;

        tribute.value = new Tribute({
            trigger: options.trigger || "@",
            values: (text, cb) => {
                if (options.fetchUsers) {
                    // If custom fetcher is provided, we assume it handles its own debouncing or it's local
                    // But to be safe, if it's async, we just call it.
                    options.fetchUsers(text).then(cb);
                } else {
                    debouncedSearch(text, cb);
                }
            },
            lookup: "name",
            fillAttr: "name",
            selectTemplate: function (item) {
                if (options.onSelect) {
                    options.onSelect(item.original);
                }
                return "@" + item.original.name;
            },
            menuItemTemplate: function (item) {
                return `
                    <div class="flex items-center gap-2 p-1">
                        <img src="${item.original.avatar || 'https://www.gravatar.com/avatar/00000000000000000000000000000000?d=mp&f=y'}" 
                             class="w-6 h-6 rounded-full object-cover">
                        <div class="flex flex-col">
                            <span class="text-sm font-medium text-[var(--text-primary)]">${item.original.name}</span>
                            <span class="text-xs text-[var(--text-tertiary)]">${item.original.email || ''}</span>
                        </div>
                    </div>
                `;
            },
            noMatchTemplate: function () {
                return '<div class="p-2 text-sm text-[var(--text-tertiary)]">No results found</div>';
            },
            menuContainer: document.body,
            ...options
        });

        tribute.value.attach(textareaRef.value);
    };

    const detach = () => {
        if (tribute.value && textareaRef.value) {
            try {
                tribute.value.detach(textareaRef.value);
            } catch (e) {
                // Ignore errors if element is already detached or invalid
            }
        }
        tribute.value = null;
    };

    onUnmounted(() => {
        detach();
        // creating a memory leak cleanup
        const menus = document.querySelectorAll(".tribute-container");
        menus.forEach(menu => menu.remove());
    });

    return {
        tribute,
        attach,
        detach
    };
}
