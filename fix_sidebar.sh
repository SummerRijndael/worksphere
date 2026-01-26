#!/bin/bash
FILE="resources/js/components/layout/AppSidebar.vue"

INSERT_BLOCK='                                <!-- Static Team Actions -->
                                <template v-if="item.id === \x27teams\x27">
                                    <div class="my-1.5 h-px bg-[var(--border-muted)]/50 mx-2"></div>
                                    <button
                                        class="flex w-full items-center gap-2 rounded-lg px-3 py-1.5 text-[12px] transition-colors duration-200 border border-transparent font-medium text-[var(--text-muted)] hover:text-[var(--interactive-primary)] hover:bg-[var(--interactive-primary)]/5"
                                        @click="navigate(\x27/teams?create=true\x27)"
                                    >
                                        <Plus class="h-3.5 w-3.5 shrink-0" />
                                        <span class="truncate text-[11.5px]">Create New Team</span>
                                    </button>
                                    <button
                                        class="flex w-full items-center gap-2 rounded-lg px-3 py-1.5 text-[12px] transition-colors duration-200 border border-transparent font-medium text-[var(--text-muted)] hover:text-[var(--interactive-primary)] hover:bg-[var(--interactive-primary)]/5"
                                        @click="navigate(\x27/teams\x27)"
                                    >
                                        <Sliders class="h-3.5 w-3.5 shrink-0" />
                                        <span class="truncate text-[11.5px]">Manage Teams</span>
                                    </button>
                                </template>'

# Unpinned section (475 is the </div> line)
sed -i "475i $INSERT_BLOCK" $FILE

# Pinned section (338 is the </div> line)
sed -i "338i $INSERT_BLOCK" $FILE
