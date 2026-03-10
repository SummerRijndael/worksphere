import test from 'node:test';
import assert from 'node:assert/strict';

import {
    createEmptyRemotePublication,
    reduceRemotePublicationState,
    type RemotePublicationEntry,
} from '../resources/js/stores/managers/legacySfuPublicationState.js';

function makeEntry(overrides: Partial<RemotePublicationEntry> = {}): RemotePublicationEntry {
    return {
        ...createEmptyRemotePublication(),
        ...overrides,
    };
}

test('rejects stale media-state versions', () => {
    const existing = makeEntry({
        sessionId: 'session-a',
        videoMid: '1',
        mediaStateVersion: 5,
    });

    const result = reduceRemotePublicationState(existing, 'PARTICIPANT-A', {
        sessionId: 'session-a',
        videoMid: '2',
        mediaStateVersion: 4,
    }, 1000);

    assert.equal(result.status, 'stale');
    assert.equal(result.shouldPull, false);
    assert.equal(result.nextEntry.videoMid, '1');
    assert.equal(result.nextEntry.mediaStateVersion, 5);
});

test('clears inherited mids when the remote session rolls over', () => {
    const existing = makeEntry({
        sessionId: 'session-a',
        audioMid: '0',
        videoMid: '1',
        screenMid: '2',
        mediaStateVersion: 2,
    });

    const result = reduceRemotePublicationState(existing, 'participant-a', {
        sessionId: 'session-b',
        videoMid: '4',
        mediaStateVersion: 3,
    }, 2000);

    assert.equal(result.status, 'applied');
    assert.equal(result.sessionChanged, true);
    assert.equal(result.shouldPull, true);
    assert.equal(result.changedKinds.audio, true);
    assert.equal(result.changedKinds.video, true);
    assert.equal(result.changedKinds.screen, true);
    assert.equal(result.nextEntry.sessionId, 'session-b');
    assert.equal(result.nextEntry.audioMid, null);
    assert.equal(result.nextEntry.videoMid, '4');
    assert.equal(result.nextEntry.screenMid, null);
});

test('treats explicit null mids as authoritative clears', () => {
    const existing = makeEntry({
        sessionId: 'session-a',
        videoMid: '1',
        screenMid: '2',
        mediaStateVersion: 7,
    });

    const result = reduceRemotePublicationState(existing, 'participant-a', {
        sessionId: 'session-a',
        videoMid: null,
        mediaStateVersion: 8,
    }, 3000);

    assert.equal(result.status, 'applied');
    assert.equal(result.explicitClears.video, true);
    assert.equal(result.explicitClears.screen, false);
    assert.equal(result.changedKinds.video, true);
    assert.equal(result.changedKinds.screen, false);
    assert.equal(result.nextEntry.videoMid, null);
    assert.equal(result.nextEntry.screenMid, '2');
});

test('suppresses duplicate pulls when publication fingerprint is unchanged', () => {
    const existing = makeEntry({
        sessionId: 'session-a',
        audioMid: '0',
        videoMid: '1',
        mediaStateVersion: 4,
    });

    const result = reduceRemotePublicationState(existing, 'participant-a', {
        sessionId: 'session-a',
        audioMid: '0',
        videoMid: '1',
        mediaStateVersion: 5,
    }, 4000);

    assert.equal(result.status, 'applied');
    assert.equal(result.fingerprintChanged, false);
    assert.equal(result.shouldPull, false);
    assert.equal(result.changedKinds.audio, false);
    assert.equal(result.changedKinds.video, false);
    assert.equal(result.changedKinds.screen, false);
});

test('marks only the changed kind when camera resumes alongside an unchanged screen share', () => {
    const existing = makeEntry({
        sessionId: 'session-a',
        videoMid: null,
        screenMid: '2',
        mediaStateVersion: 9,
    });

    const result = reduceRemotePublicationState(existing, 'participant-a', {
        sessionId: 'session-a',
        videoMid: '1',
        screenMid: '2',
        mediaStateVersion: 10,
    }, 5000);

    assert.equal(result.status, 'applied');
    assert.equal(result.shouldPull, true);
    assert.equal(result.changedKinds.audio, false);
    assert.equal(result.changedKinds.video, true);
    assert.equal(result.changedKinds.screen, false);
});
