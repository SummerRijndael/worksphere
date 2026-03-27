import { onBeforeUnmount, onMounted, unref, type MaybeRefOrGetter } from "vue";

const DEFAULT_MESSAGE =
    "A call is currently active. Reloading or leaving this page will disconnect the call.";

export function useBeforeUnloadGuard(
    shouldWarn: MaybeRefOrGetter<boolean>,
    message = DEFAULT_MESSAGE,
): void {
    const onBeforeUnload = (event: BeforeUnloadEvent) => {
        if (!unref(shouldWarn)) {
            return;
        }

        event.preventDefault();
        event.returnValue = message;
        return message;
    };

    onMounted(() => {
        window.addEventListener("beforeunload", onBeforeUnload);
    });

    onBeforeUnmount(() => {
        window.removeEventListener("beforeunload", onBeforeUnload);
    });
}
