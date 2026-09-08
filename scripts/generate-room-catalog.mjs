#!/usr/bin/env node
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

const boardsPath = process.env.BOARDS_TARGET || resolve(process.argv[2] || '', 'boards-target.json');
const chatPath = process.env.LIVE_CHAT_TARGET || resolve(process.argv[2] || '', 'live-chat-target.json');
const outPath = process.env.OUT || resolve('resources/room-catalog.json');

const boards = JSON.parse(readFileSync(boardsPath, 'utf8'));
const chat = JSON.parse(readFileSync(chatPath, 'utf8'));
const brands = (boards.primaryBoards || []).filter((b) => b.groupId === 'brands');
const rooms = brands.map((b) => ({
  roomKey: `${b.key}-live`,
  name: `${b.name} Live`,
  scopeType: 'board',
  scopeKey: b.key,
}));
for (const r of chat.additionalRooms || []) {
  rooms.push({
    roomKey: r.roomKey,
    name: r.roomName,
    scopeType: r.scopeType,
    scopeKey: r.scopeKey,
  });
}
if (brands.length !== 41 || rooms.length !== 42) {
  console.error(`unexpected counts brands=${brands.length} rooms=${rooms.length}`);
  process.exit(1);
}
const doc = {
  schemaVersion: 1,
  kind: 'flatrate-live-chat-room-catalog',
  generatedFrom: {
    boardsTarget: boardsPath,
    liveChatTarget: chatPath,
    controlRepoNote: 'embedded snapshot; no runtime wiki filesystem dependency',
  },
  brandRoomCount: 41,
  generalRoomCount: 1,
  totalRoomCount: 42,
  rooms,
};
writeFileSync(outPath, JSON.stringify(doc, null, 2) + '\n');
console.log(`wrote ${outPath} rooms=${rooms.length}`);
