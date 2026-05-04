# Subnet Allocation Tree

The **Subnet Allocation Tree** panel on the IPv4 tab visualises how a set of child CIDRs fit within a parent block.

## Input

- **Parent CIDR** — the parent network (e.g. `10.0.0.0/16`).
- **Child CIDRs** — one CIDR per line; all must be sub-networks of the parent.

Click **Build Tree** to generate the tree.

## Output

The tree shows:

- **Allocated nodes** — each child CIDR you entered, nested under its direct parent.
- **Gap nodes** (shown in muted/italic text) — address ranges within the parent that are not covered by any child. Gap blocks are expressed as CIDR notation using the range → CIDR algorithm.
- **Hierarchy** — children that contain other children are shown as nested lists.

## Example

```text
10.0.0.0/16
├── 10.0.0.0/24    (allocated)
│   ├── 10.0.0.0/25  (allocated)
│   └── 10.0.0.128/25  (allocated)
└── 10.0.1.0/24 … 10.0.255.0/24  (gap — free space)
```

## REST API

`POST /api/v1/tree` — see [REST API](api.md#post-apiv1tree).

## Interactive Tree Editor (v3.0.0+)

The **Tree Editor** is a separate Tools-drawer panel on the IPv4 tab. It lets you
build and edit a hierarchical subnet plan in your browser without round-tripping
to the server for each edit.

### Operations

- **Click a node** to open the split picker; choose 2 / 4 / 8 / 16 equal children.
- **Drag a node onto a sibling** (desktop) to merge the two back into the parent.
- **Tap a node** on a touch device to open an action sheet with Split, Merge,
  and Rename actions (drag-merge is disabled on touch).
- **Click the pencil icon** on any node to set a name and free-form notes.
- **`Ctrl/Cmd+Z`** undoes the last edit; **`Ctrl/Cmd+Shift+Z`** redoes it. The
  undo stack is capped at 50 entries.

### Persistence

- **Autosave** — every edit is written to `localStorage` under
  `sc.tree.draft.<rootCidr>` after a 300 ms debounce. Reloading the page
  restores the in-progress tree.
- **Save Session** — POSTs a `type: 'tree'` payload to `/api/v1/sessions`. The
  returned 8-hex-char ID is appended to the page URL so you can bookmark it
  or share it. Server-side validation runs `tree_validate()` (canonical-form
  CIDR, containment, non-overlap with gaps allowed, name ≤128 / notes ≤1024,
  depth ≤16, total nodes ≤1024).

### Exports

- **Copy as CIDR / Markdown / Cisco** — clipboard copies for the leaf set or
  full annotated tree.
- **CSV / JSON download** — CSV is lossy (one row per node, name + notes
  flattened); JSON is the full lossless `type: 'tree'` payload.
- **Share URL** — base64url-encodes the tree state into `?tree=…`. Soft-cap
  is 50 nodes (~3 KB after encoding); above that, the editor prompts you to
  Save Session and share the session URL instead.

### Mobile

The mobile interaction model is desktop-first with a touch fallback: on
`@media (hover: none)` devices, tapping a node opens a bottom action sheet
instead of the click-split picker, and drag-merge is disabled.
