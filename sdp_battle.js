
import sdpTransform from 'sdp-transform';

// A "Real" Chrome SDP for an SCTP DataChannel (Simplified)
const chromeGoldenSdp = [
    "v=0",
    "o=- 0 0 IN IP4 127.0.0.1",
    "s=-",
    "t=0 0",
    "a=group:BUNDLE 0",
    "a=msid-semantic: WMS",
    "m=application 9 UDP/DTLS/SCTP webrtc-datachannel",
    "c=IN IP4 0.0.0.0",
    "a=mid:0",
    "a=sctp-port:5000",
    "a=max-message-size:2147483647", // The Chrome Bug Line
    "a=setup:actpass"
].join("\r\n") + "\r\n";

function lineFilterMunge(sdp) {
    return sdp.split(/\r?\n/)
        .filter(line => !line.includes('a=max-message-size:'))
        .join('\r\n') + '\r\n';
}

function sdpTransformMunge(sdp) {
    const parsed = sdpTransform.parse(sdp);
    if (parsed.media) {
        parsed.media.forEach(m => {
            if (m.maxMessageSize) m.maxMessageSize = 65536;
        });
    }
    return sdpTransform.write(parsed);
}

console.log("--- BATTLE OF THE NORMALIZERS ---");

console.log("\n[Option A] Line Filter (Surgical Removal)");
const optA = lineFilterMunge(chromeGoldenSdp);
console.log(JSON.stringify(optA));

console.log("\n[Option B] sdp-transform (Prod Style Patch)");
const optB = sdpTransformMunge(chromeGoldenSdp);
console.log(JSON.stringify(optB));

const diffA = optA.includes("a=sctp-port:5000\r\na=setup:actpass");
const diffB = optB.includes("a=sctp-port:5000\r\na=setup:actpass");

console.log("\n--- Structural Analysis ---");
console.log("Option A (Removal) preserves sequence?", diffA);
console.log("Option B (Transform) preserves sequence?", optB.includes("a=setup:actpass") && optB.includes("a=sctp-port:5000"));

// Check for "Foreign Artifacts" in sdp-transform
if (optB.includes("msid-semantic:  WMS")) {
    console.log("\n[!] sdp-transform added extra whitespace to msid-semantic.");
}
