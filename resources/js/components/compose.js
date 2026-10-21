/**
 * Merge Alpine state objects while preserving getters.
 *
 * Object spread ({...a, ...b}) *invokes* a getter and copies the resulting
 * value, so a computed property like `get ready()` would be frozen at the value
 * it had when the component was constructed — permanently false, in practice.
 * That silently broke every booking screen: the fare list never appeared and
 * the submit button never enabled, because `ready` / `canSubmit` never changed.
 *
 * Copying property descriptors instead keeps getters as getters, so Alpine's
 * reactivity re-evaluates them like it does on a plain component object.
 */
export function compose(...sources) {
    const target = {};

    for (const source of sources) {
        Object.defineProperties(target, Object.getOwnPropertyDescriptors(source));
    }

    return target;
}
