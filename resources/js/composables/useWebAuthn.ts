import api from "@/lib/api";

/**
 * WebAuthn composable for passkey registration and authentication.
 */
export function useWebAuthn() {
    /**
     * Check if WebAuthn is supported in the browser.
     */
    const isSupported = (): boolean => {
        return !!(
            window.PublicKeyCredential &&
            typeof window.PublicKeyCredential === "function"
        );
    };

    /**
     * Check if platform authenticator (Touch ID, Face ID, Windows Hello) is available.
     */
    const isPlatformAuthenticatorAvailable = async (): Promise<boolean> => {
        if (!isSupported()) return false;
        try {
            return await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
        } catch {
            return false;
        }
    };

    /**
     * Convert base64url to ArrayBuffer.
     */
    const base64UrlToArrayBuffer = (base64url: string): ArrayBuffer => {
        const base64 = base64url.replace(/-/g, "+").replace(/_/g, "/");
        const padding = "=".repeat((4 - (base64.length % 4)) % 4);
        const binary = atob(base64 + padding);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes.buffer;
    };

    /**
     * Convert ArrayBuffer to base64url.
     */
    const arrayBufferToBase64Url = (buffer: ArrayBuffer): string => {
        const bytes = new Uint8Array(buffer);
        let binary = "";
        for (let i = 0; i < bytes.length; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=/g, "");
    };

    /**
     * Register a new passkey.
     */
    const registerPasskey = async (passkeyName?: string): Promise<{ success: boolean; error?: string }> => {
        try {
            // Get registration options from server
            const optionsRes = await api.post("/api/user/passkeys/register/options");
            const options = optionsRes.data;

            // Convert challenge and user.id from base64url to ArrayBuffer
            const publicKeyOptions: PublicKeyCredentialCreationOptions = {
                ...options,
                challenge: base64UrlToArrayBuffer(options.challenge),
                user: {
                    ...options.user,
                    id: base64UrlToArrayBuffer(options.user.id),
                },
                excludeCredentials: options.excludeCredentials?.map((cred: any) => ({
                    ...cred,
                    id: base64UrlToArrayBuffer(cred.id),
                })) || [],
            };

            // Call browser WebAuthn API to create credential
            const credential = await navigator.credentials.create({
                publicKey: publicKeyOptions,
            }) as PublicKeyCredential;

            if (!credential) {
                return { success: false, error: "Passkey creation was cancelled." };
            }

            const response = credential.response as AuthenticatorAttestationResponse;

            // Send credential to server
            await api.post("/api/user/passkeys", {
                id: credential.id,
                rawId: arrayBufferToBase64Url(credential.rawId),
                type: credential.type,
                response: {
                    clientDataJSON: arrayBufferToBase64Url(response.clientDataJSON),
                    attestationObject: arrayBufferToBase64Url(response.attestationObject),
                },
                name: passkeyName || "Passkey",
            });

            return { success: true };
        } catch (error: any) {
            console.error("Passkey registration error:", error);
            
            if (error.name === "NotAllowedError") {
                return { success: false, error: "Passkey registration was cancelled or timed out." };
            }
            if (error.name === "InvalidStateError") {
                return { success: false, error: "This device is already registered as a passkey." };
            }
            
            return { 
                success: false, 
                error: error.response?.data?.message || error.message || "Failed to register passkey." 
            };
        }
    };

    /**
     * Authenticate with a passkey.
     */
    const authenticateWithPasskey = async (): Promise<{ success: boolean; user?: any; error?: string }> => {
        try {
            // Get authentication options from server
            const optionsRes = await api.post("/api/auth/passkey/login/options");
            const options = optionsRes.data;

            // Convert challenge from base64url to ArrayBuffer
            const publicKeyOptions: PublicKeyCredentialRequestOptions = {
                ...options,
                challenge: base64UrlToArrayBuffer(options.challenge),
                allowCredentials: options.allowCredentials?.map((cred: any) => ({
                    ...cred,
                    id: base64UrlToArrayBuffer(cred.id),
                })) || [],
            };

            // Call browser WebAuthn API to get credential
            const credential = await navigator.credentials.get({
                publicKey: publicKeyOptions,
            }) as PublicKeyCredential;

            if (!credential) {
                return { success: false, error: "Passkey authentication was cancelled." };
            }

            const response = credential.response as AuthenticatorAssertionResponse;

            // Send credential to server for verification
            const loginRes = await api.post("/api/auth/passkey/login", {
                id: credential.id,
                rawId: arrayBufferToBase64Url(credential.rawId),
                type: credential.type,
                response: {
                    clientDataJSON: arrayBufferToBase64Url(response.clientDataJSON),
                    authenticatorData: arrayBufferToBase64Url(response.authenticatorData),
                    signature: arrayBufferToBase64Url(response.signature),
                    userHandle: response.userHandle ? arrayBufferToBase64Url(response.userHandle) : null,
                },
            });

            return { success: true, user: loginRes.data.data?.user };
        } catch (error: any) {
            console.error("Passkey authentication error:", error);
            
            if (error.name === "NotAllowedError") {
                return { success: false, error: "Passkey authentication was cancelled or timed out." };
            }
            
            return { 
                success: false, 
                error: error.response?.data?.message || error.message || "Passkey authentication failed." 
            };
        }
    };

    return {
        isSupported,
        isPlatformAuthenticatorAvailable,
        registerPasskey,
        authenticateWithPasskey,
    };
}
