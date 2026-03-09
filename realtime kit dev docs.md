---
title: Build using Core SDK · Cloudflare Realtime docs
description: To integrate the Core SDK, you will need to initialize it with a
  participant's auth token, and then use the provided SDK APIs to control the
  peer in the session.
lastUpdated: 2026-01-19T06:33:39.000Z
chatbotDeprioritize: false
source_url:
  html: https://developers.cloudflare.com/realtime/realtimekit/core/
  md: https://developers.cloudflare.com/realtime/realtimekit/core/index.md
---

### Initialize Core SDK

To integrate the Core SDK, you will need to initialize it with a [participant's auth token](https://developers.cloudflare.com/api/resources/realtime_kit/#create-a-participant), and then use the provided SDK APIs to control the peer in the session.

Initialization might differ slightly based on your tech stack. Please choose your preferred tech stack below.


  const authToken = <auth-token>;

  const meetingDefaultOptions = {
    audio: true,
    video: true,
  };

  RealtimeKitClient.init({
    authToken,
    defaults: meetingDefaultOptions, // optional
  }).then((meeting) => {
    // next - meeting.join();
  });



----
### Advanced Options
init({
  authToken: "<auth_token>",
  defaults: {
    video: true,
    audio: true,
    mediaConfiguration: {
      // Configure custom video quality (e.g., 1080p). Disable simulcast using `simulcastConfig` override for single-layer streaming.
      video: {
        width: { ideal: 1920 },
        height: { ideal: 1080 },
        frameRate: { ideal: 15 },
      },
      screenshare: {
        frameRate: { ideal: 15, max: 30 }, // Default 5
        displaySurface: "monitor", // Given surface is suggested to the end user
      },
    },
  },
  overrides: {
    simulcastConfig: {
      // If you want to disable simulcast
      disable: false,
      // If you want to pass custom simulcast encodings
      encodings: [
        {
          rid: "f", // full / highest quality
          scaleResolutionDownBy: 1.0,
          maxBitrate: 2500000, // ~2.5 Mbps
        },
        {
          rid: "h", // half
          scaleResolutionDownBy: 2.0,
          maxBitrate: 900000, // ~0.9 Mbps
        },
        {
          rid: "q", // quarter
          scaleResolutionDownBy: 4.0,
          maxBitrate: 250000, // ~0.25 Mbps
        },
      ],
    },
    forceRelay: false, // forceRelay, if true, TURN will be preferred over STUN
  },
  modules: {
    devTools: {
      logs: true, // Prints SDK logs to console, Useful in initial integration phase
    },
  },
  onError: (error) => {
    console.error(error); // SDK errors, Useful in detecting common issues
  },
});

---

---
title: Meeting Object Explained · Cloudflare Realtime docs
description: The meeting object is the core interface for interacting with a
  RealtimeKit session. It provides access to participants, local user controls,
  chat, polls, plugins, and more. This object is returned when you initialize
  the SDK.
lastUpdated: 2026-01-19T06:33:39.000Z
chatbotDeprioritize: false
source_url:
  html: https://developers.cloudflare.com/realtime/realtimekit/core/meeting-object-explained/
  md: https://developers.cloudflare.com/realtime/realtimekit/core/meeting-object-explained/index.md
---

The meeting object is the core interface for interacting with a RealtimeKit session. It provides access to participants, local user controls, chat, polls, plugins, and more. This object is returned when you initialize the SDK.

Prerequisites

This page assumes you've already initialized the SDK as described in the [Initialize SDK](https://developers.cloudflare.com/realtime/realtimekit/core/) guide.

This guide covers the core namespaces on the meeting object along with the most commonly used properties, methods, and events. Individual namespace references have been linked for more details.

## Meeting Object Structure

The meeting object contains several properties that organize different aspects of the meeting:

### Self/Local Participant

## Remote participants

### `meeting.participants` - All Remote Participants

## Meeting metadata

### `meeting.meta` - Meeting Metadata

## Chat

### `meeting.chat` - Chat Messages

## Polls

### `meeting.polls` - Polls

## Plugins

### `meeting.plugins` - Plugins

## AI features

### `meeting.ai` - AI Features

## Methods

Join or leave a meeting room:

## Understanding IDs

RealtimeKit uses two types of identifiers for participants:

* **Session ID (`id`)**: Unique identifier for each connection to a meeting. Changes every time a participant joins a new session. On Web platforms, this is called "Peer ID" and stored in `meeting.self.id` or `participant.id`. On mobile platforms, this is called "Participant ID" and stored in `meeting.localUser.id` or `participant.id`.

* **User ID (`userId`)**: Persistent identifier for a participant across multiple sessions. Remains the same when a user reconnects. This is stored in `meeting.self.userId` (Web) or `meeting.localUser.userId` (Mobile), and `participant.userId` for remote participants.

**When to use each:**

* Use `userId` when you need to track the same user across different sessions or reconnections (for example, saving user preferences or permissions)
* Use `id` when working with the current session's connections (for example, managing active video streams or real-time participant states)

## Best Practices

* **Listen to events instead of polling**: The meeting object emits events when state changes occur. Subscribe to these events rather than continuously checking property values.

* **Work with participant collections**: On Web platforms, use `toArray()` to convert participant maps to arrays. On mobile platforms, participant collections are already lists that you can iterate through directly.

* **Check connection state**: Always check `roomJoined` (or `meeting.localUser.roomJoined` on mobile) before accessing properties or calling methods that require an active session.

* **Handle errors gracefully**: Many methods accept error callbacks. Always implement proper error handling to provide a good user experience.

## Next Steps

Now that you understand the meeting object structure, you can use it to build custom meeting experiences. The UI Kit components internally use this same meeting object to provide ready-to-use interfaces. In the next guide, we'll show you how to combine UI Kit components with direct meeting object access to create your own custom UI.

--

// Participant identifiers
meeting.self.id; // Peer ID (unique per session)
meeting.self.userId; // Participant ID (persistent across sessions)
meeting.self.name; // Participant display name

// Media state
meeting.self.audioEnabled; // Boolean: Is audio enabled?
meeting.self.videoEnabled; // Boolean: Is video enabled?
meeting.self.screenShareEnabled; // Boolean: Is screen share active?

// Media tracks
meeting.self.audioTrack; // Audio MediaStreamTrack, if audio is enabled
meeting.self.videoTrack; // Video MediaStreamTrack, if video is enabled
meeting.self.screenShareTracks; // Structure: { audio: MediaStreamTrack, video: MediaStreamTrack }, if screen share is enabled

// Room state
meeting.self.roomJoined; // Boolean: Has joined the meeting?
meeting.self.roomState; // Current room state

-

// Media controls
await meeting.self.enableAudio(); // Emits a `audioUpdate` event on `meeting.self` when successful.
await meeting.self.disableAudio(); // Emits a `audioUpdate` event on `meeting.self` when successful.
await meeting.self.enableVideo(); // Emits a `videoUpdate` event on `meeting.self` when successful.
await meeting.self.disableVideo(); // Emits a `videoUpdate` event on `meeting.self` when successful.
await meeting.self.enableScreenShare(); // Emits a `screenShareUpdate` event on `meeting.self` when successful.
await meeting.self.disableScreenShare(); // Emits a `screenShareUpdate` event on `meeting.self` when successful.

// Update Name
await meeting.self.setName("New Name"); // setName works only works before joining the meeting

// List Devices
await meeting.self.getAllDevices(); // Returns all available devices
await meeting.self.getAudioDevices(); // Returns all available audio devices
await meeting.self.getVideoDevices(); // Returns all available video devices
await meeting.self.getSpeakerDevices(); // Returns all available speaker devices
await meeting.self.getCurrentDevices(); // {audio: MediaDevice, video: MediaDevice, speaker: MediaDevice} Returns the current device configuration

// Change a device
await meeting.self.setDevice((await meeting.self.getAllDevices())[0]);

--

// All participants who have joined
meeting.participants.joined; // Map of joined participants
meeting.participants.joined.toArray(); // Array of joined participants

// Participants with active media
meeting.participants.active; // Map of participants with active audio/video
meeting.participants.active.toArray(); // Array of participants with active audio/video

// Participants in waiting room
meeting.participants.waitlisted; // Map of waitlisted participants
meeting.participants.waitlisted.toArray(); // Array of waitlisted participants

// Pinned participants
meeting.participants.pinned; // Map of pinned participants
meeting.participants.pinned.toArray(); // Array of pinned participants

-

// Get all joined participants as an array
const joinedParticipants = meeting.participants.joined.toArray();

// Access first participant's IDs
const firstParticipant = joinedParticipants[0];
console.log("First Participant Peer ID:", firstParticipant?.id); // Peer ID (unique per session)
console.log("First Participant User ID:", firstParticipant?.userId); // Participant ID (persistent)
console.log("First Participant Name:", firstParticipant?.name); // Display name
console.log("First Participant Audio Enabled:", firstParticipant?.audioEnabled); // Audio state
console.log("First Participant Video Enabled:", firstParticipant?.videoEnabled); // Video state
console.log(
  "First Participant Screen Share Enabled:",
  firstParticipant?.screenShareEnabled,
); // Screen share state
console.log("First Participant Audio Track:", firstParticipant?.audioTrack); // Audio MediaStreamTrack
console.log("First Participant Video Track:", firstParticipant?.videoTrack); // Video MediaStreamTrack
console.log(
  "First Participant Screen Share Track:",
  firstParticipant?.screenShareTracks,
); // Screen share MediaStreamTrack

// Access participant by peer ID
const participant = meeting.participants.joined.get("peer-id");

// Get count of joined participants
const count = meeting.participants.joined.size();
--

participant.id; // Peer ID
participant.userId; // Participant ID
participant.name; // Display name
participant.audioEnabled; // Audio state
participant.videoEnabled; // Video state
participant.screenShareEnabled; // Screen share state
participant.audioTrack; // Audio MediaStreamTrack
participant.videoTrack; // Video MediaStreamTrack
participant.screenShareTrack; // Screen share MediaStreamTrack

-

meeting.meta.meetingId; // Meeting identifier
meeting.meta.meetingTitle; // Meeting Title
meeting.meta.meetingStartedTimestamp; // Meeting start time

--

---
title: Meeting Metadata · Cloudflare Realtime docs
description: All metadata pertaining to a meeting is stored in meeting.meta.
  This includes important information about the meeting state, type, and
  connections.
lastUpdated: 2026-01-13T15:01:55.000Z
chatbotDeprioritize: false
source_url:
  html: https://developers.cloudflare.com/realtime/realtimekit/core/meeting-metadata/
  md: https://developers.cloudflare.com/realtime/realtimekit/core/meeting-metadata/index.md
---

All metadata pertaining to a meeting is stored in `meeting.meta`. This includes important information about the meeting state, type, and connections.

## Available metadata

Select a framework based on the platform you are building for.

## Access meeting metadata

To access meeting metadata, use the `meeting.meta` object.

## Connection events

The `meta` object also emits events for indicating changes in the connection state of the meeting.

### Media connection updates

### Socket connection updates

## Next steps

Explore related topics:

* [Meeting Object Explained](https://developers.cloudflare.com/realtime/realtimekit/core/meeting-object-explained/) - Comprehensive meeting object reference
* [Session Lifecycle](https://developers.cloudflare.com/realtime/realtimekit/concepts/session-lifecycle/) - Understanding meeting states and transitions



--

meeting.meta.on("mediaConnectionUpdate", ({ transport, state }) => {
  // transport - 'consuming' | 'producing'
  // state - 'new' | 'connecting' | 'connected' | 'disconnected' | 'reconnecting' | 'failed'

  console.log(`Media connection ${transport} is now ${state}`);
});

--

---
title: Local Participant · Cloudflare Realtime docs
description: Manage local user media devices, control audio, video, and
  screenshare, and handle events in RealtimeKit meetings.
lastUpdated: 2026-01-13T15:01:55.000Z
chatbotDeprioritize: false
source_url:
  html: https://developers.cloudflare.com/realtime/realtimekit/core/local-participant/
  md: https://developers.cloudflare.com/realtime/realtimekit/core/local-participant/index.md
---

Manage local user media devices, control audio, video, and screenshare, and handle events in RealtimeKit meetings.

Prerequisites

Initialize the SDK and understand the meeting object structure. Refer to [Initialize SDK](https://developers.cloudflare.com/realtime/realtimekit/core/) and [Meeting Object Explained](https://developers.cloudflare.com/realtime/realtimekit/core/meeting-object-explained/).

## Introduction

The local user is accessible via `meeting.self` and contains all information and methods related to the current participant. This includes media controls, device management, participant metadata, and state information.

## Properties

### Metadata Properties

Access participant identifiers and display information:

### Media Properties

Access the local user's media tracks and states:

### State Properties

Access room state and participant status:

## Media Controls

### Audio control

Mute and unmute the microphone:

### Video control

Enable and disable the camera:

### Screen share control

Start and stop screen sharing:

### Change display name

Update the display name before joining the meeting:

## Manage media devices

### Get available devices

### Change device

Switch to a different media device:

## Display local video

## Screen share setup (iOS)

## Events

### Room joined

Fires when the local user joins the meeting:

### Room left

Fires when the local user leaves the meeting:

### Video update

Fires when video is enabled or disabled:

### Audio update

Fires when audio is enabled or disabled:

### Screen share update

Fires when screen sharing starts or stops:

### Device update

Fires when the active device changes:

### Device List Update

Triggered when the list of available devices changes (device plugged in or out):

### Network Quality Score

Monitor your own network quality:

### Permission Updates

Triggered when permissions are updated dynamically:

### Media Permission Errors

Triggered when media permissions are denied or media capture fails:

### Waitlist Status

For meetings with waiting room enabled:

### iOS-Specific Events

The iOS SDK provides additional platform-specific events:

## Pin and Unpin

Pin or unpin yourself in the meeting (requires appropriate permissions):

## Update Media Constraints

Update video or screenshare resolution at runtime:


// Participant identifiers
meeting.self.id; // Peer ID (unique per session)
meeting.self.userId; // User ID (persistent across sessions)
meeting.self.customParticipantId; // Custom identifier set by developer
meeting.self.name; // Display name
meeting.self.picture; // Display picture URL
--

// Media state flags
meeting.self.audioEnabled; // Boolean: Is audio enabled?
meeting.self.videoEnabled; // Boolean: Is video enabled?
meeting.self.screenShareEnabled; // Boolean: Is screen share active?

// Media tracks (MediaStreamTrack objects)
meeting.self.audioTrack; // Audio MediaStreamTrack (available when audioEnabled is true)
meeting.self.videoTrack; // Video MediaStreamTrack (available when videoEnabled is true)
meeting.self.screenShareTracks; // Object: { video: MediaStreamTrack, audio?: MediaStreamTrack }

// Permissions granted by user
meeting.self.mediaPermissions; // Current audio/video permissions

--

// Get all media devices
const devices = await meeting.self.getAllDevices();

// Get all audio input devices (microphones)
const audioDevices = await meeting.self.getAudioDevices();

// Get all video input devices (cameras)
const videoDevices = await meeting.self.getVideoDevices();

// Get all audio output devices (speakers)
const speakerDevices = await meeting.self.getSpeakerDevices();

-


// Get device by ID
const device = await meeting.self.getDeviceById("device-id", "audio");

// Get current devices being used
const currentDevices = meeting.self.getCurrentDevices();
// Returns: { audio: MediaDeviceInfo, video: MediaDeviceInfo, speaker: MediaDeviceInfo }

--

// Get all devices
const devices = await meeting.self.getAllDevices();

// Set a specific device (replaces device of the same kind)
await meeting.self.setDevice(devices[0]);

-

<video id="local-video" autoplay playsinline></video>

--

const videoElement = document.getElementById("local-video");

// Register the video element to display video
meeting.self.registerVideoElement(videoElement);

// For local preview (not sent to other users), pass true as second argument
meeting.self.registerVideoElement(videoElement, true);

-

meeting.self.deregisterVideoElement(videoElement);

--

meeting.self.on("roomJoined", () => {
  console.log("Successfully joined the meeting");
});

-

meeting.self.on("videoUpdate", ({ videoEnabled, videoTrack }) => {
  console.log("Video state:", videoEnabled);

  if (videoEnabled) {
    // Video track is available, can display it
    const videoElement = document.getElementById("my-video");
    const stream = new MediaStream();
    stream.addTrack(videoTrack);
    videoElement.srcObject = stream;
    videoElement.play();
  }
});

-


meeting.self.on("audioUpdate", ({ audioEnabled, audioTrack }) => {
  console.log("Audio state:", audioEnabled);

  if (audioEnabled) {
    // Audio track is available
    console.log("Microphone is on");
  }
});


-


meeting.self.on(
  "screenShareUpdate",
  ({ screenShareEnabled, screenShareTracks }) => {
    console.log("Screen share state:", screenShareEnabled);

    if (screenShareEnabled) {
      // Screen share tracks are available
      const screenElement = document.getElementById("my-screen-share");
      const stream = new MediaStream();
      stream.addTrack(screenShareTracks.video);
      if (screenShareTracks.audio) {
        stream.addTrack(screenShareTracks.audio);
      }
      screenElement.srcObject = stream;
      screenElement.play();
    }
  },
);

--

meeting.self.on("deviceUpdate", ({ device }) => {
  // Handle device change
  if (device.kind === "audioinput") {
    console.log("Microphone changed:", device.label);
  } else if (device.kind === "videoinput") {
    console.log("Camera changed:", device.label);
  } else if (device.kind === "audiooutput") {
    console.log("Speaker changed:", device.label);
  }
});

--

meeting.self.on(
  "mediaScoreUpdate",
  ({ kind, isScreenshare, score, scoreStats }) => {
    if (kind === "video") {
      console.log(
        `Your ${isScreenshare ? "screenshare" : "video"} quality score is`,
        score,
      );
    }

    if (kind === "audio") {
      console.log("Your audio quality score is", score);
    }

    if (score < 5) {
      console.log("Your media quality is poor");
    }
  },
);

-

// Audio Producer
{
  "kind": "audio",
  "isScreenshare": false,
  "score": 10,
  "participantId": "meeting.self.id",
  "scoreStats": {
    "score": 10,
    "bitrate": 22452,
    "packetsLostPercentage": 0,
    "jitter": 0,
    "isScreenShare": false
  }
}

// Video Producer
{
  "kind": "video",
  "isScreenshare": false,
  "score": 10,
  "participantId": "meeting.self.id",
  "scoreStats": {
    "score": 10,
    "frameWidth": 640,
    "frameHeight": 480,
    "framesPerSecond": 24,
    "jitter": 0,
    "isScreenShare": false,
    "packetsLostPercentage": 0,
    "bitrate": 576195,
    "cpuLimitations": false,
    "bandwidthLimitations": false
  }
}

-

meeting.self.on("mediaPermissionError", ({ message, kind }) => {
  console.log(`Failed to capture ${kind}: ${message}`);

  // Handle different error types
  if (message === "DENIED") {
    console.log("User denied permission");
  } else if (message === "SYSTEM_DENIED") {
    console.log("System denied permission");
  } else if (message === "COULD_NOT_START") {
    console.log("Failed to start media stream");
  }
});

-

---
title: Remote Participants · Cloudflare Realtime docs
description: This guide explains how to access participant data, display videos,
  handle events, and manage participant permissions in your RealtimeKit
  meetings.
lastUpdated: 2026-01-20T03:13:13.000Z
chatbotDeprioritize: false
source_url:
  html: https://developers.cloudflare.com/realtime/realtimekit/core/remote-participants/
  md: https://developers.cloudflare.com/realtime/realtimekit/core/remote-participants/index.md
---

This guide explains how to access participant data, display videos, handle events, and manage participant permissions in your RealtimeKit meetings.

Prerequisites

This page assumes you've already initialized the SDK and understand the meeting object structure. Refer to [Initialize SDK](https://developers.cloudflare.com/realtime/realtimekit/core/) and [Meeting Object Explained](https://developers.cloudflare.com/realtime/realtimekit/core/meeting-object-explained/) if needed.

The participant object contains all information related to a particular participants, including information about the grid and each participants media streams, name, and state variables. It is accessible via `meeting.participants`.

### Properties

### Access participant properties

### Access participant object

You can fetch a participant from the [participant maps](#participant-maps).

## Participant Maps

## View Modes

The view mode indicates whether participants are populated in `ACTIVE_GRID` mode or `PAGINATED` mode.

* **`ACTIVE_GRID` mode** - Participants are automatically replaced in `meeting.participants.active` based on who is speaking or who has their video turned on
* **`PAGINATED` mode** - Participants in `meeting.participants.active` are fixed. Use `setPage()` to change the active participants

### Set view mode

### Set page in paginated mode

### Monitor view mode

## Host Controls

The participant object allows the host several controls. These can be selected while creating the host [preset](https://developers.cloudflare.com/api/resources/realtime_kit/subresources/presets/methods/create/).

### Media controls

With the correct permissions, the host can disable media for remote participants.

### Waiting room controls

The waiting room allows the host to control which users can join your meeting and when. They can either choose to accept or reject the request.

You can also automate this flow so that users join the meeting automatically when the host joins the meeting, using [presets](https://developers.cloudflare.com/api/resources/realtime_kit/subresources/presets/methods/create/).

#### Accept waiting room request

#### Reject waiting room request

### Pin participants

The host can choose to pin or unpin participants to the grid.

### Update participant permissions

## Display participant videos


-

const participant = meeting.participants.joined.get(participantId);

// Access participant properties
console.log(participant.name);
console.log(participant.videoEnabled);
console.log(participant.audioEnabled);

-

// Get all joined participants
const joinedParticipants = meeting.participants.joined;

// Get active participants (those on screen)
const activeParticipants = meeting.participants.active;

// Get pinned participants
const pinnedParticipants = meeting.participants.pinned;

// Get waitlisted participants
const waitlistedParticipants = meeting.participants.waitlisted;
-


// Set the view mode to paginated
await meeting.participants.setViewMode("PAGINATED");

// Set the view mode to active grid
await meeting.participants.setViewMode("ACTIVE_GRID");

-

const participant = meeting.participants.joined.get(participantId);

// Disable a participant's video stream
participant.disableVideo();

// Disable a participant's audio stream
participant.disableAudio();

// Kick a participant from the meeting
participant.kick();

-

await meeting.participants.acceptWaitingRoomRequest(participantId);

-

await meeting.participants.rejectWaitingRoomRequest(participantId);

-

const participant = meeting.participants.joined.get(participantId);

// Pin a participant
await participant.pin();

// Unpin a participant
await participant.unpin();

-


const participantIds = meeting.participants.joined
  .toArray()
  .filter((e) => e.name.startsWith("John"))
  .map((p) => p.id);

-

// Allow file upload permissions in public chat
const newPermissions = {
  chat: {
    public: {
      files: true,
    },
  },
};

meeting.participants.updatePermissions(participantIds, newPermissions);

--

interface UpdatedPermissions {
  polls?: {
    canCreate?: boolean;
    canVote?: boolean;
  };
  plugins?: {
    canClose?: boolean;
    canStart?: boolean;
  };
  chat?: {
    public?: {
      canSend?: boolean;
      text?: boolean;
      files?: boolean;
    };
    private?: {
      canSend?: boolean;
      text?: boolean;
      files?: boolean;
    };
  };
}

--

<video class="participant-video" id="participant-video"></video>

-

// Get the video element
const videoElement = document.getElementById("participant-video");

// Get the participant
const participant = meeting.participants.joined.get(participantId);

// Register the video element
participant.registerVideoElement(videoElement);

-
meeting.self.registerVideoElement(videoElement, true);

-

participant.deregisterVideoElement(videoElement);

--

---
title: Events · Cloudflare Realtime docs
description: This page provides an overview of the events emitted by
  meeting.participants and related participant maps, which you can use to keep
  your UI in sync with changes such as participants joining or leaving, pinning
  updates, active speaker changes, and grid view mode or page changes.
lastUpdated: 2026-01-20T03:13:13.000Z
chatbotDeprioritize: false
source_url:
  html: https://developers.cloudflare.com/realtime/realtimekit/core/remote-participants/events/
  md: https://developers.cloudflare.com/realtime/realtimekit/core/remote-participants/events/index.md
---

This page provides an overview of the events emitted by `meeting.participants` and related participant maps, which you can use to keep your UI in sync with changes such as participants joining or leaving, pinning updates, active speaker changes, and grid view mode or page changes.

Prerequisites

This page assumes you have already initialized the SDK and understand the meeting object structure. Refer to [Initialize SDK](https://developers.cloudflare.com/realtime/realtimekit/core/) and [Meeting Object Explained](https://developers.cloudflare.com/realtime/realtimekit/core/meeting-object-explained/) if needed.

## Grid events

These events allow you to monitor changes to the grid.

### View mode change

### Page change

### Active speaker

Triggered when a participant starts speaking.

## Participant map events

These events allow you to monitor changes to remote participant maps. Use them to get notified when a participant joins or leaves the meeting, is pinned, or moves out of the grid.

### Participant joined

Triggered when any participant joins the meeting.

### Participant left

Triggered when any participant leaves the meeting.

### Active participants changed

### Participant pinned

Triggered when a participant is pinned.

### Participant unpinned

Triggered when a participant is unpinned.

## Participant events

You can monitor changes to a specific participant using the following events.

### Video update

Triggered when any participant starts or stops video.

### Audio update

Triggered when any participant starts or stops audio.

### Screen share update

Triggered when any participant starts or stops screen share.

### Network quality score

## Listen to participant events


-

meeting.participants.on(
  "viewModeChanged",
  ({ viewMode, currentPage, pageCount }) => {
    console.log("view mode changed", viewMode);
  },
);

-



--

meeting.participants.on(
  "pageChanged",
  ({ viewMode, currentPage, pageCount }) => {
    console.log("page changed", currentPage);
  },
);

-

meeting.participants.on("activeSpeaker", (participant) => {
  console.log(`${participant.id} is currently speaking`);
});

-

meeting.participants.joined.on("videoUpdate", (participant) => {
  console.log(
    `A participant with id "${participant.id}" updated their video track`,
  );

  if (participant.videoEnabled) {
    // Use participant.videoTrack
  } else {
    // Handle stop video
  }
});

-

meeting.participants.joined.on("audioUpdate", (participant) => {
  console.log(
    `A participant with id "${participant.id}" updated their audio track`,
  );

  if (participant.audioEnabled) {
    // Use participant.audioTrackmeeting.participants.joined.on(
  "mediaScoreUpdate",
  ({ participantId, kind, isScreenshare, score, scoreStats }) => {
    if (kind === "video") {
      console.log(
        `Participant ${participantId}'s ${isScreenshare ? "screenshare" : "video"} quality score is`,
        score,
      );
    }

    if (kind === "audio") {
      console.log(
        `Participant ${participantId}'s audio quality score is`,
        score,
      );
    }

    if (score < 5) {
      console.log(`Participant ${participantId}'s media quality is poor`);
    }
  },
);

-

meeting.participants.joined.on(
  "audioUpdate",
  (participant, { audioEnabled, audioTrack }) => {
    console.log(
      "The participant with id",
      participant.id,
      "has toggled their mic to",
      audioEnabled,
    );
  },
);

-

meeting.participants.joined
  .get(participantId)
  .on("audioUpdate", ({ audioEnabled, audioTrack }) => {
    console.log(
      "The participant with id",
      participantId,
      "has toggled their mic to",
      audioEnabled,
    );
  });

-

---
title: Video Effects · Cloudflare Realtime docs
description: Add video background effects and blur to participant video feeds in
  your RealtimeKit meetings using the Core SDK.
lastUpdated: 2026-01-13T15:01:55.000Z
chatbotDeprioritize: false
source_url:
  html: https://developers.cloudflare.com/realtime/realtimekit/core/video-effects/
  md: https://developers.cloudflare.com/realtime/realtimekit/core/video-effects/index.md
---

Add video background effects and blur to participant video feeds in your RealtimeKit meetings using the Core SDK.

Note

If you are using the `rtk-meeting` component with UI Kit and prefer a higher-level abstraction, refer to [UI Kit Addons](https://developers.cloudflare.com/realtime/realtimekit/ui-kit/addons/) instead.
npm i @cloudflare/realtimekit-virtual-background

import RealtimeKitVideoBackgroundTransformer from "@cloudflare/realtimekit-virtual-background";

const videoBackgroundTransformer =
  await RealtimeKitVideoBackgroundTransformer.init({
    meeting,
  });

--

const imageUrl = "https://images.unsplash.com/photo-1487088678257-3a541e6e3922";

meeting.self.addVideoMiddleware(
  await videoBackgroundTransformer.createStaticBackgroundVideoMiddleware(
    imageUrl,
  ),
);

-

meeting.self.addVideoMiddleware(
  await videoBackgroundTransformer.createBackgroundBlurVideoMiddleware(50),
);

-
if (RealtimeKitVideoBackgroundTransformer.isSupported()) {
  const videoBackgroundTransformer =
    await RealtimeKitVideoBackgroundTransformer.init({
      meeting: meeting,
    });

  meeting.self.addVideoMiddleware(
    await videoBackgroundTransformer.createStaticBackgroundVideoMiddleware(
      imageUrl,
    ),
  );
}

const videoBackgroundTransformer =
  await RealtimeKitVideoBackgroundTransformer.init({
    meeting,
    segmentationConfig: {
      model: "mlkit", // 'meet' | 'mlkit'
      backend: "wasmSimd",
      inputResolution: "256x256", // '256x144' for meet
      pipeline: "webgl2", // 'webgl2' | 'canvas2dCpu'
      // canvas2dCpu gives sharper blur, webgl2 is faster
      targetFps: 35,
    },
  });


--


---
title: Media Acquisition Approaches · Cloudflare Realtime docs
description: RealtimeKit provides flexible approaches for acquiring and managing
  participant media (audio and video tracks). By default, the SDK handles media
  acquisition automatically when you initialize it. However, certain use cases
  require accessing media tracks before or independently of SDK initialization.
lastUpdated: 2026-01-20T12:32:36.000Z
chatbotDeprioritize: false
source_url:
  html: https://developers.cloudflare.com/realtime/realtimekit/core/media-acquisition-approaches/
  md: https://developers.cloudflare.com/realtime/realtimekit/core/media-acquisition-approaches/index.md
---

Note

This guide assumes that you are already familiar with [initializing the RealtimeKit SDK](https://developers.cloudflare.com/realtime/realtimekit/core/).

RealtimeKit provides flexible approaches for acquiring and managing participant media (audio and video tracks). By default, the SDK handles media acquisition automatically when you initialize it. However, certain use cases require accessing media tracks before or independently of SDK initialization.


-

const authToken = "<auth-token>";
const meeting = await RealtimeKitClient.init({
  authToken,
});

console.log("audioTrack:: ", meeting.self.audioTrack);
console.log("videoTrack:: ", meeting.self.videoTrack);

-

const mediaFromSDK = await RealtimeKitClient.initMedia({
  video: true,
  audio: true,
});

setTimeout(() => {
  const authToken = "<auth-token>";
  RealtimeKitClient.init({
    authToken,
    defaults: {
      mediaHandler: mediaFromSDK,
    },
  }).then((meeting) => {
    // next - meeting.join();
  });
}, 5000);

--


const authToken = "<auth-token>";
const meeting = await RealtimeKitClient.init({
  authToken,
});

let audioTrack; // Put the audioTrack that you acquired from browser here
let videoTrack; // Put the videoTrack that you acquired from browser here
await meeting.self.enableAudio(audioTrack);
await meeting.self.enableVideo(videoTrack);

-

---
title: Configure Video Settings · Cloudflare Realtime docs
description: Video codecs are software programs that compress and decompress
  digital video data for transmission, storage, or playback. Configuring the
  appropriate video codec can help reduce file size, enhance video quality, and
  ensure compatibility with different playback devices.
lastUpdated: 2025-12-01T15:18:21.000Z
chatbotDeprioritize: false
source_url:
  html: https://developers.cloudflare.com/realtime/realtimekit/recording-guide/configure-codecs/
  md: https://developers.cloudflare.com/realtime/realtimekit/recording-guide/configure-codecs/index.md
---

Video codecs are software programs that compress and decompress digital video data for transmission, storage, or playback. Configuring the appropriate video codec can help reduce file size, enhance video quality, and ensure compatibility with different playback devices.

## Configure Codecs

You can modify the codec which is used for recording the videos. We currently support the following codecs:

* **H264 (default)**: Records video using the H.264 codec with 1280px × 720px resolution, and 384 kbps AAC audio in MP4 container.
* **VP8**: Records video using the VP8 codec with 1280px × 720px resolution, and Vorbis codec audio in WebM container.

You can change the codec by specifying the codec in the `video_config` field in the [Start Recording API](https://developers.cloudflare.com/api/resources/realtime_kit/subresources/recordings/methods/start_recordings/), for example:

```json
{
  "video_config": {
    "codec": "H264"
  }
}
```

## Download Video Files

The video file for your recording is generated only if you passed the `video_config` parameters in the [Start Recording API](https://developers.cloudflare.com/api/resources/realtime_kit/subresources/recordings/methods/start_recordings/).

When the recording is completed, you can use the `downloadUrl` provided in the response body of the [Start Recording API](https://developers.cloudflare.com/api/resources/realtime_kit/subresources/recordings/methods/start_recordings/) to download and export the video file.


--

{
  "video_config": {
    "codec": "H264"
  }
}

--


  } else {
    // Handle stop audio
  }
});

-

meeting.participants.joined.on("screenShareUpdate", (participant) => {
  console.log(
    `A participant with id "${participant.id}" updated their screen share`,
  );

  if (participant.screenShareEnabled) {
    // Use participant.screenShareTracks
  } else {
    // Handle stop screen share
  }
});

-


