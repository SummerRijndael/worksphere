
const originalSdp = "v=0\r\no=- 42 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\na=msid-semantic: WMS\r\nm=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\nc=IN IP4 0.0.0.0\r\na=mid:0\r\na=sctp-port:5000\r\na=max-message-size:262144\r\na=setup:actpass\r\n";

console.log("--- TEST 1: Surgical Removal Regex ---");
const regex = /a=max-message-size:.*\r?\n/g;
const regex2 = /a=sctp-port:.*\r?\n/g;

let test1 = originalSdp.replace(regex, "");
test1 = test1.replace(regex2, "");

console.log("Original lines count:", originalSdp.split('\n').length);
console.log("Result lines count:", test1.split('\n').length);
console.log("Result SDP Content:");
console.log(JSON.stringify(test1));

if (test1.includes("a=setup:actpass") && !test1.includes("a=sctp-port:5000a=setup:actpass")) {
    console.log("\n[Result] Structure looks valid for actpass.");
} else {
    console.log("\n[Result] STRUCTURE CORRUPTED!");
}

console.log("\n--- TEST 2: Line-by-line Filter (The Safest Way) ---");
function safeMunge(sdp) {
    return sdp.split(/\r?\n/)
        .filter(line => !line.toLowerCase().includes('max-message-size') && !line.toLowerCase().includes('sctp-port'))
        .join('\r\n') + '\r\n';
}

const test2 = safeMunge(originalSdp);
console.log("Result TEST 2 Content:");
console.log(JSON.stringify(test2));
