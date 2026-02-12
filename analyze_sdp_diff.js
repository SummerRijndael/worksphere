import sdpTransform from "sdp-transform";

const chromeSdp = `v=0
o=- 4771427508381283626 2 IN IP4 127.0.0.1
s=-
t=0 0
a=msid-semantic: WMS
m=application 9 UDP/DTLS/SCTP webrtc-datachannel
c=IN IP4 0.0.0.0
a=mid:0
a=sctp-port:5000
a=max-message-size:262144
a=msid:stream1 track1
`;

console.log("--- ORIGINAL CHROME SDP ---");
console.log(chromeSdp);

const parsed = sdpTransform.parse(chromeSdp);
const reconstructed = sdpTransform.write(parsed);

console.log("\n--- RECONSTRUCTED VIA SDP-TRANSFORM ---");
console.log(reconstructed);

// Diff check
if (chromeSdp.trim() === reconstructed.trim()) {
    console.log("\n[Result] Identical roundtrip.");
} else {
    console.log("\n[Result] ROUNDTRIP DIFFS FOUND!");
}
