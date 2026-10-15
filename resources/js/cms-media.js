/*
 * The media library.
 *
 * One Alpine component owns the whole screen: which folder is open, what the
 * grid is filtered by, what is selected, which file the details panel shows
 * and the queue of the uploads. The markup is in the blade of the section —
 * nothing here builds HTML out of strings — and every word an editor reads
 * comes from `labels`, which the server filled from
 * `resources/lang/{ru,en}/library.php`.
 *
 * Two kinds of request on purpose. Everything but an upload goes through
 * `fetch`. An upload goes through
 * `XMLHttpRequest`, because that is the only one of the two that reports how
 * much of a file has gone up, and a queue without a progress bar is a spinner
 * with extra steps.
 */

/* How many files are pushed at once. Three keeps a slow connection busy
   without turning one impatient drop of thirty files into thirty sockets. */
const UPLOAD_PARALLEL = 3;

/* How long a name may be before the middle of it is folded away. */
const NAME_LIMIT = 26;

/* How long the queue stays on screen once every file of it has landed. It
   goes by itself, because a list of green lines is in the way of the grid it
   just filled; a queue with a refusal in it stays until the editor closes it. */
const QUEUE_LINGER = 2500;

/**
 * Everything the library is made of: the grid with its filters and its
 * cursor, the tree of the folders, what is selected, the details of one file
 * and the queue of the uploads.
 *
 * It is a factory and not the component itself because the picker dialog is
 * the same grid, the same tree and the same queue in a dialog. It composes
 * this object and adds what choosing a file means:
 *
 *     Alpine.data('cmsMediaPicker', config => ({
 *         ...window.cmsMediaGridCore(config),
 *         pick(item) { ... },
 *     }))
 *
 * One name on `window`, on purpose and not by accident: the package ships no
 * build step, so two inline scripts have no other way to share a function.
 */
function cmsMediaGridCore(config = {}) {
    return {
        token: config.token || '',
        endpoints: config.endpoints || {},
        labels: config.labels || {},
        locales: config.locales || [],
        locale: config.defaultLocale || '',
        stateKey: 'cms-media',

        /* ------------------------------------------------------- the grid */
        q: '',
        kind: '',
        withoutAlt: false,
        unused: false,
        sort: 'newest',
        view: 'grid',
        disk: config.disk || '',
        /* Which kinds an upload from this screen may store. The section takes
           everything the configuration describes and leaves it empty; a picker
           hands over what the field declares, and the server narrows the very
           same allowlist by it. */
        allowedKinds: config.allowedKinds || [],
        folder: null,
        items: [],
        nextCursor: null,
        loading: false,

        /* ---------------------------------------------------- the folders */
        tree: config.tree || { root: { count: 0 }, folders: [] },
        expanded: {},
        creating: false,
        creatingName: '',
        renaming: null,
        renamingName: '',

        /* -------------------------------------------------- what is taken */
        selection: [],
        anchor: null,
        dragged: [],
        dropFolder: null,
        dragging: false,
        dragDepth: 0,

        /* ------------------------------------------------------ the panel */
        details: null,
        detailsOrigin: null,
        captionOpen: false,

        /* ----------------------------------------------------- the queue  */
        uploads: [],
        jobId: 0,
        running: 0,

        /* ---------------------------------------------------- the dialogs */
        confirmText: '',
        confirmUsages: [],
        confirmTargets: [],
        moveTargets: [],
        moveFolder: null,
        movePick: null,

        init() {
            this.view = this.restore('view', 'grid');
            this.sort = this.restore('sort', 'newest');
            this.reload();
        },

        /* ------------------------------------------------ what is remembered */

        restore(key, fallback) {
            try {
                const stored = sessionStorage.getItem(this.stateKey + ':' + key);

                return stored === null ? fallback : JSON.parse(stored);
            } catch (e) {
                return fallback;
            }
        },

        persist(key, value) {
            try {
                sessionStorage.setItem(this.stateKey + ':' + key, JSON.stringify(value));
            } catch (e) {
                // A browser that refuses storage still shows the library.
            }
        },

        /* ---------------------------------------------------------- reading */

        filtered() {
            return this.q !== '' || this.kind !== '' || this.withoutAlt || this.unused || this.folder !== null;
        },

        params(cursor) {
            const params = new URLSearchParams();

            if (this.q !== '') params.set('q', this.q);
            if (this.kind !== '') params.set('kind', this.kind);
            if (this.withoutAlt) params.set('without_alt', '1');
            if (this.unused) params.set('unused', '1');
            if (this.folder !== null) params.set('folder', String(this.folder));
            if (cursor) params.set('cursor', cursor);

            params.set('sort', this.sort);
            params.set('view', this.view);

            return params;
        },

        async reload() {
            this.persist('sort', this.sort);
            this.loading = true;

            const body = await this.get(this.endpoints.items + '?' + this.params(null).toString());

            this.loading = false;

            if (body === null) {
                return;
            }

            this.items = body.items || [];
            this.nextCursor = body.next_cursor || null;
            this.selection = this.selection.filter(id => this.items.some(item => item.id === id));
        },

        async loadMore() {
            if (!this.nextCursor || this.loading) {
                return;
            }

            this.loading = true;

            const body = await this.get(this.endpoints.items + '?' + this.params(this.nextCursor).toString());

            this.loading = false;

            if (body === null) {
                return;
            }

            this.items = this.items.concat(body.items || []);
            this.nextCursor = body.next_cursor || null;
        },

        async refreshTree() {
            const body = await this.get(this.endpoints.folders);

            if (body !== null) {
                this.tree = body;
            }
        },

        setKind(kind) {
            this.kind = kind;
            this.reload();
        },

        setView(view) {
            this.view = view;
            this.persist('view', view);
        },

        /* ---------------------------------------------------------- folders */

        /** The tree as one list, with the depth of every node and the subtree
         *  of a collapsed folder left out. Alpine has no recursive template,
         *  and a flat list with an indent is what the markup draws anyway. */
        visibleFolders() {
            const rows = [];

            const walk = (nodes, depth) => {
                nodes.forEach(node => {
                    rows.push({ ...node, depth });

                    if (this.expanded[node.id]) {
                        walk(node.children || [], depth + 1);
                    }
                });
            };

            walk(this.tree.folders || [], 0);

            return rows;
        },

        /** Every folder, open or not: a picker has nothing to collapse. */
        pickerFolders() {
            const rows = [];
            const forbidden = this.moveFolder;

            const walk = (nodes, depth, disabled) => {
                nodes.forEach(node => {
                    const own = disabled || node.id === forbidden;

                    rows.push({ id: node.id, name: node.name, depth, disabled: own });
                    walk(node.children || [], depth + 1, own);
                });
            };

            walk(this.tree.folders || [], 0, false);

            return rows;
        },

        folderName(id) {
            if (id === null || id === undefined) {
                return this.labels.folders.root;
            }

            const found = this.findFolder(this.tree.folders || [], id);

            return found === null ? this.labels.folders.root : found.name;
        },

        findFolder(nodes, id) {
            for (const node of nodes) {
                if (node.id === id) {
                    return node;
                }

                const inside = this.findFolder(node.children || [], id);

                if (inside !== null) {
                    return inside;
                }
            }

            return null;
        },

        toggleFolder(id) {
            this.expanded[id] = !this.expanded[id];
        },

        selectFolder(id) {
            this.folder = id;
            this.clearSelection();
            this.reload();
        },

        startCreateFolder(parent) {
            this.creating = parent === null ? 0 : parent;
            this.creatingName = '';
            this.$nextTick(() => this.$refs.newFolder && this.$refs.newFolder.focus());
        },

        async createFolder() {
            const name = this.creatingName.trim();

            if (name === '') {
                this.creating = false;

                return;
            }

            const body = await this.post(this.endpoints.folderCreate, {
                parent: this.creating === 0 ? null : this.creating,
                name,
            });

            this.creating = false;

            if (body !== null) {
                this.tree = body.tree;
            }
        },

        startRenameFolder(node) {
            this.renaming = node.id;
            this.renamingName = node.name;
            this.$nextTick(() => this.$refs.rename && this.$refs.rename.focus());
        },

        async renameFolder(node) {
            if (this.renaming !== node.id) {
                return;
            }

            const name = this.renamingName.trim();

            this.renaming = null;

            if (name === '' || name === node.name) {
                return;
            }

            const body = await this.post(this.url('folderRename', node.id), { name });

            if (body !== null) {
                this.tree = body.tree;
            }
        },

        openFolderMove(node) {
            this.moveTargets = [];
            this.moveFolder = node.id;
            this.movePick = node.parent_id === undefined ? null : node.parent_id;
            this.openDialog('cms-media-move');
        },

        async deleteFolder(node) {
            const body = await this.post(this.url('folderDelete', node.id), {});

            if (body !== null) {
                this.tree = body.tree;

                if (this.folder === node.id) {
                    this.selectFolder(null);
                }
            }
        },

        /* -------------------------------------------------------- selection */

        isSelected(id) {
            return this.selection.includes(id);
        },

        toggle(item, index, event) {
            if (event && event.shiftKey && this.anchor !== null) {
                this.range(index);

                return;
            }

            this.anchor = index;

            this.selection = this.isSelected(item.id)
                ? this.selection.filter(id => id !== item.id)
                : this.selection.concat([item.id]);
        },

        range(index) {
            const from = Math.min(this.anchor, index);
            const to = Math.max(this.anchor, index);
            const ids = this.items.slice(from, to + 1).map(item => item.id);

            this.selection = this.selection.concat(ids.filter(id => !this.isSelected(id)));
        },

        clearSelection() {
            this.selection = [];
            this.anchor = null;
        },

        /* ---------------------------------------------------------- details */

        async openDetails(item, event) {
            // A click with a modifier is a selection and not an opening: it is
            // how a range is picked without the panel jumping about.
            if (event && (event.shiftKey || event.metaKey || event.ctrlKey)) {
                const index = this.items.findIndex(row => row.id === item.id);

                this.toggle(item, index, event);

                return;
            }

            const body = await this.get(this.url('show', item.id));

            if (body === null) {
                return;
            }

            // Which tile the panel was opened from, so that closing it puts
            // the caret back where the editor left it instead of at the top
            // of the document.
            this.detailsOrigin = item.id;
            this.details = this.withLocales(body.media);
            this.captionOpen = this.locales.some(code => (this.details.caption[code] || '') !== '');

            // The panel itself and not the first field in it: an editor who
            // clicks a tile while typing must not find the next letter going
            // into the name of a file. This only moves the place Tab starts
            // from and lets a screen reader say the panel has opened.
            const root = this.$root;

            this.$nextTick(() => {
                const panel = root.querySelector('.cms-panel');

                if (panel && !panel.contains(document.activeElement)) {
                    panel.focus();
                }
            });
        },

        withLocales(media) {
            const alt = {};
            const caption = {};

            this.locales.forEach(code => {
                alt[code] = (media.alt || {})[code] || '';
                caption[code] = (media.caption || {})[code] || '';
            });

            return { ...media, alt, caption };
        },

        closeDetails() {
            const origin = this.detailsOrigin;
            // Read now and not inside the tick: `$root` answers through the
            // element the call came from, and the button that closes the panel
            // is one of the elements the panel takes with it.
            const root = this.$root;

            this.details = null;
            this.detailsOrigin = null;

            // The panel is gone from the document, so whatever had the focus
            // inside it has nothing to hand it to: without this the next Tab
            // starts again at the top of the page.
            this.$nextTick(() => {
                const tile = origin === null
                    ? null
                    : root.querySelector('[data-cms-media="' + window.CSS.escape(origin) + '"]');

                if (tile) {
                    tile.focus();
                }
            });
        },

        async saveDetails() {
            if (this.details === null) {
                return;
            }

            const body = await this.post(this.url('update', this.details.id), {
                name: this.details.name,
                alt: this.details.alt,
                caption: this.details.caption,
            });

            if (body === null) {
                return;
            }

            // What came back replaces everything the editor does not have a
            // cursor in. The three fields they type into are kept as they
            // stand: a save of the description used to land while somebody
            // was typing the name, and the answer of that save put the old
            // name back under their hands.
            this.details = {
                ...body.media,
                name: this.details.name,
                alt: this.details.alt,
                caption: this.details.caption,
            };

            this.replaceItem(body.media);
        },

        replaceItem(media) {
            this.items = this.items.map(item => (item.id === media.id ? { ...item, ...media } : item));
        },

        /* ---------------------------------------------------------- moving */

        openMove(ids) {
            this.moveTargets = ids.slice();
            this.moveFolder = null;
            this.movePick = this.folder;
            this.openDialog('cms-media-move');
        },

        async confirmMove() {
            const folder = this.movePick;

            this.closeDialog('cms-media-move');

            if (this.moveFolder !== null) {
                const body = await this.post(this.url('folderMove', this.moveFolder), { parent: folder });

                if (body !== null) {
                    this.tree = body.tree;
                }

                this.moveFolder = null;

                return;
            }

            await this.moveFiles(this.moveTargets, folder);
        },

        async moveFiles(ids, folder) {
            if (ids.length === 0) {
                return;
            }

            const body = await this.post(this.endpoints.move, { uuids: ids, folder });

            if (body === null) {
                return;
            }

            this.clearSelection();
            await this.reload();
            await this.refreshTree();

            if (this.details !== null && ids.includes(this.details.id)) {
                this.details = { ...this.details, folder_id: folder };
            }
        },

        /* -------------------------------------------------------- deleting */

        askDelete(ids) {
            this.confirmTargets = ids.slice();
            this.confirmUsages = [];

            if (ids.length === 1) {
                const item = this.items.find(row => row.id === ids[0]);
                const name = item ? item.name : (this.details ? this.details.name : '');

                this.confirmText = this.labels.confirm.delete_one.replace(':name', name);
            } else {
                this.confirmText = this.labels.confirm.delete_many.replace(':count', ids.length);
            }

            this.openDialog('cms-media-delete');
        },

        async confirmDelete() {
            const ids = this.confirmTargets.slice();
            const body = await this.post(this.endpoints.delete, { uuids: ids }, true);

            if (body === null) {
                return;
            }

            // A file that is still on a page comes back with the places it
            // stands in, and the dialog turns into that list instead of
            // offering the button again.
            if (body.usages) {
                this.confirmText = body.message;
                this.confirmUsages = body.usages;

                return;
            }

            this.closeDialog('cms-media-delete');
            this.toast(body.message, body.report && body.report.deleted.length === 0 ? 'error' : 'success');

            if (this.details !== null && ids.includes(this.details.id) && body.report && body.report.deleted.includes(this.details.id)) {
                this.details = null;
            }

            this.clearSelection();
            await this.reload();
            await this.refreshTree();
        },

        /* ------------------------------------------------- dragging inside */

        startDrag(item, event) {
            this.dragged = this.isSelected(item.id) ? this.selection.slice() : [item.id];

            if (event && event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', this.dragged.join(','));
            }
        },

        endDrag() {
            this.dragged = [];
            this.dropFolder = null;
        },

        async dropOnFolder(id) {
            this.dropFolder = null;

            const ids = this.dragged.slice();

            this.dragged = [];

            if (ids.length === 0) {
                return;
            }

            await this.moveFiles(ids, id);
        },

        /* ------------------------------------------------ dragging from out */

        hasFiles(event) {
            const types = event && event.dataTransfer ? event.dataTransfer.types : null;

            return types ? Array.from(types).includes('Files') : false;
        },

        dragEnter(event) {
            if (!this.hasFiles(event)) {
                return;
            }

            this.dragDepth += 1;
            this.dragging = true;
        },

        dragOver(event) {
            if (this.hasFiles(event) && event.dataTransfer) {
                event.dataTransfer.dropEffect = 'copy';
            }
        },

        dragLeave(event) {
            if (!this.hasFiles(event)) {
                return;
            }

            // A drag over a page fires `dragleave` on every element it passes,
            // so the overlay counts its way in and out instead of closing on
            // the first one.
            this.dragDepth = Math.max(0, this.dragDepth - 1);
            this.dragging = this.dragDepth > 0;
        },

        dropTitle() {
            return this.folder === null
                ? this.labels.uploader.drop_root
                : this.labels.uploader.drop_folder.replace(':folder', this.folderName(this.folder));
        },

        dropUpload(event) {
            this.dragDepth = 0;
            this.dragging = false;

            if (!this.hasFiles(event)) {
                return;
            }

            this.enqueue(Array.from(event.dataTransfer.files || []));
        },

        /* -------------------------------------------------------- uploading */

        picked(event) {
            this.enqueue(Array.from(event.target.files || []));
            event.target.value = '';
        },

        enqueue(files) {
            files.forEach(file => {
                this.jobId += 1;

                this.uploads.push({
                    id: this.jobId,
                    name: file.name,
                    file,
                    progress: 0,
                    state: 'queued',
                    message: '',
                    duplicate: null,
                    force: false,
                });
            });

            this.pump();
        },

        pump() {
            while (this.running < UPLOAD_PARALLEL) {
                const job = this.uploads.find(candidate => candidate.state === 'queued');

                if (!job) {
                    break;
                }

                this.send(job);
            }

            if (this.running === 0) {
                this.refreshTree();
                this.forget();
            }
        },

        /** The queue puts itself away once there is nothing left to answer.
         *  The identifiers are compared afterwards so that files dropped in
         *  the meantime are not swept away with the finished ones. */
        forget() {
            if (this.uploads.length === 0 || this.uploads.some(job => job.state !== 'done')) {
                return;
            }

            const shown = this.uploads.map(job => job.id).join(',');

            window.setTimeout(() => {
                if (this.uploads.map(job => job.id).join(',') === shown) {
                    this.uploads = [];
                }
            }, QUEUE_LINGER);
        },

        send(job) {
            this.running += 1;
            job.state = 'uploading';
            job.progress = 0;
            job.message = '';

            const data = new FormData();

            data.append('file', job.file);

            if (this.folder !== null) data.append('folder', String(this.folder));
            if (this.disk) data.append('disk', this.disk);
            if (job.force) data.append('force', '1');

            this.allowedKinds.forEach(kind => data.append('allowed_kinds[]', kind));

            const request = new XMLHttpRequest();

            request.open('POST', this.endpoints.upload);
            request.setRequestHeader('X-CSRF-TOKEN', this.token);
            request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            request.setRequestHeader('Accept', 'application/json');

            request.upload.onprogress = event => {
                job.progress = event.lengthComputable ? Math.round((event.loaded / event.total) * 100) : 50;
            };

            request.onload = () => {
                this.running -= 1;

                let body = {};

                try {
                    body = JSON.parse(request.responseText || '{}');
                } catch (e) {
                    body = {};
                }

                this.settle(job, request.status, body);
                this.pump();
            };

            request.onerror = () => {
                this.running -= 1;
                job.state = 'failed';
                job.message = this.labels.messages.request_failed;
                this.reveal(job);
                this.pump();
            };

            request.send(data);
        },

        settle(job, status, body) {
            if (status >= 400) {
                job.state = 'failed';
                job.progress = 100;
                job.message = body.message || this.labels.messages.request_failed;
                this.reveal(job);

                return;
            }

            if (body.duplicate) {
                // The state of the row already reads «Такой файл уже есть»,
                // so the line under it stays empty: the two buttons are the
                // question, and saying it twice is not asking it better.
                job.state = 'duplicate';
                job.progress = 100;
                job.message = '';
                job.duplicate = body.duplicate;

                return;
            }

            job.state = 'done';
            job.progress = 100;
            job.message = '';

            if (body.media) {
                this.prepend(body.media);
            }
        },

        /** A refusal names the formats that would have been kept, which is
         *  three lines of text at the bottom of a list that scrolls. It is
         *  brought into view: an error an editor has to go looking for is an
         *  error they do not read. */
        reveal(job) {
            const root = this.$root;

            this.$nextTick(() => {
                const row = root.querySelector('[data-cms-job="' + job.id + '"]');

                if (row) {
                    row.scrollIntoView({ block: 'nearest' });
                }
            });
        },

        /** A file that has just landed goes to the front of the grid, where
         *  the newest of them belong, without asking the server for the page
         *  again: an editor who dropped ten files watches them appear. */
        prepend(media) {
            if (this.items.some(item => item.id === media.id)) {
                this.replaceItem(media);

                return;
            }

            this.items = [media].concat(this.items);
        },

        retry(job) {
            job.state = 'queued';
            job.message = '';
            this.pump();
        },

        uploadCopy(job) {
            job.force = true;
            job.state = 'queued';
            job.message = '';
            job.duplicate = null;
            this.pump();
        },

        useExisting(job) {
            const media = job.duplicate;

            job.state = 'done';
            job.message = '';
            job.duplicate = null;

            if (!media) {
                return;
            }

            const root = this.$root;

            this.prepend(media);
            this.selection = this.isSelected(media.id) ? this.selection : this.selection.concat([media.id]);
            this.forget();

            this.$nextTick(() => {
                const tile = root.querySelector('[data-cms-media="' + window.CSS.escape(media.id) + '"]');

                if (tile) {
                    tile.scrollIntoView({ block: 'nearest' });
                }
            });
        },

        doneUploads() {
            return this.uploads.filter(job => job.state === 'done').length;
        },

        stateLabel(job) {
            const states = this.labels.uploader;

            if (job.state === 'queued') return states.queued;
            if (job.state === 'uploading') return states.uploading;
            if (job.state === 'failed') return states.failed;
            if (job.state === 'duplicate') return states.duplicate;

            return states.done;
        },

        /* --------------------------------------------------------- replacing */

        async replacePicked(event) {
            const file = (event.target.files || [])[0];

            event.target.value = '';

            if (!file || this.details === null) {
                return;
            }

            const data = new FormData();

            data.append('file', file);

            const body = await this.sendForm(this.url('replace', this.details.id), data);

            if (body === null) {
                return;
            }

            this.details = this.withLocales(body.media);
            this.replaceItem(body.media);
            this.toast(body.message, 'success');
        },

        /* ---------------------------------------------------------- keyboard */

        onGridKey(event) {
            const tiles = Array.from(this.$root.querySelectorAll('.cms-tile'));
            const current = tiles.indexOf(document.activeElement);

            if (current === -1) {
                return;
            }

            const columns = this.columns(tiles);
            let next = null;

            if (event.key === 'ArrowRight') next = current + 1;
            if (event.key === 'ArrowLeft') next = current - 1;
            if (event.key === 'ArrowDown') next = current + columns;
            if (event.key === 'ArrowUp') next = current - columns;

            if (next !== null) {
                event.preventDefault();

                if (tiles[next]) {
                    tiles[next].focus();
                }

                return;
            }

            if (event.key === ' ' || event.key === 'Spacebar') {
                event.preventDefault();
                this.toggle(this.items[current], current, event);

                return;
            }

            if (event.key === 'Enter') {
                event.preventDefault();
                this.openDetails(this.items[current], null);
            }
        },

        /** How many tiles stand in a row, read off the page rather than
         *  guessed: the grid wraps by itself and the number changes with the
         *  width of the window. */
        columns(tiles) {
            if (tiles.length < 2) {
                return 1;
            }

            const top = tiles[0].offsetTop;
            let columns = 0;

            while (columns < tiles.length && tiles[columns].offsetTop === top) {
                columns += 1;
            }

            return Math.max(1, columns);
        },

        /** What a double click on a tile does. Nothing here: the section
         *  opens the details on the first click already, and a second one has
         *  nothing left to say. The picker takes the file and closes. */
        confirmTile() {
        },

        onEscape() {
            if (this.creating !== false) {
                this.creating = false;

                return;
            }

            if (this.details !== null) {
                this.closeDetails();

                return;
            }

            this.clearSelection();
        },

        /* ----------------------------------------------------------- words */

        /** A long name folded in the middle: the beginning says what the file
         *  is and the end keeps the part that tells two of them apart. */
        shorten(name, limit = NAME_LIMIT) {
            const text = String(name || '');

            if (text.length <= limit) {
                return text;
            }

            const head = Math.ceil((limit - 1) / 2);
            const tail = Math.floor((limit - 1) / 2);

            return text.slice(0, head) + '…' + text.slice(text.length - tail);
        },

        /* --------------------------------------------------------- requests */

        url(name, id) {
            return String(this.endpoints[name] || '').replace('__id__', encodeURIComponent(id));
        },

        /**
         * The modal of MoonShine has one entry point and it toggles, so
         * closing a dialog the editor had already closed — with Esc, with the
         * cross, by clicking beside it — would open it again, empty. The state
         * is therefore read off the component before the event is sent.
         */
        setDialog(name, open) {
            const dialog = this.dialogState(name);

            if (dialog !== null && dialog.open === open) {
                return;
            }

            window.MoonShine.ui.toggleModal(name);
        },

        dialogState(name) {
            const root = this.$root.querySelector('[data-cms-dialog="' + name + '"]');

            if (!root || !window.Alpine) {
                return null;
            }

            const data = window.Alpine.$data(root);

            return data && 'open' in data ? data : null;
        },

        openDialog(name) {
            this.setDialog(name, true);
        },

        closeDialog(name) {
            this.setDialog(name, false);
        },

        toast(message, type = 'success') {
            if (message) {
                window.MoonShine.ui.toast(message, type);
            }
        },

        async get(url) {
            let response;

            try {
                response = await fetch(url, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                    },
                });
            } catch (e) {
                this.toast(this.labels.messages.request_failed, 'error');

                return null;
            }

            const body = await response.json().catch(() => ({}));

            if (!response.ok) {
                this.toast(body.message || this.labels.messages.request_failed, 'error');

                return null;
            }

            return body;
        },

        /**
         * `quiet` is for the one caller that reads the refusal itself: the
         * deletion of a file that is still on a page answers with the list of
         * those pages, and a toast over the dialog showing it would say the
         * same thing twice.
         */
        async post(url, payload, quiet = false) {
            let response;

            try {
                response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': this.token,
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(payload),
                });
            } catch (e) {
                this.toast(this.labels.messages.request_failed, 'error');

                return null;
            }

            const body = await response.json().catch(() => ({}));

            if (!response.ok) {
                if (quiet && body.usages) {
                    return body;
                }

                this.toast(body.message || this.labels.messages.request_failed, 'error');

                return null;
            }

            if (body.message && !quiet) {
                this.toast(body.message, 'success');
            }

            return body;
        },

        async sendForm(url, data) {
            let response;

            try {
                response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': this.token,
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                    },
                    body: data,
                });
            } catch (e) {
                this.toast(this.labels.messages.request_failed, 'error');

                return null;
            }

            const body = await response.json().catch(() => ({}));

            if (!response.ok) {
                this.toast(body.message || this.labels.messages.request_failed, 'error');

                return null;
            }

            return body;
        },
    };
}

window.cmsMediaGridCore = cmsMediaGridCore;

/* Where the picker remembers the folder an editor was last in, one entry per
   place a file is chosen for. It is a convenience of one browser and nothing
   more, so it lives in `localStorage` and never travels to the server. */
const PICKER_FOLDER = 'cms-media-picker:folder:';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('cmsMediaLibrary', (config = {}) => cmsMediaGridCore(config));

    /*
     * The picker dialog.
     *
     * The same grid, the same tree and the same queue as the section — the
     * core above is the whole of it — plus the one thing the section has no
     * use for: choosing. What it takes away is everything that is not
     * choosing, so a click opens no details panel and a file is never renamed,
     * moved or deleted from here.
     *
     * Which field asked travels with the answer. The address carried a key and
     * the event carries it back, so five pictures on one card never pick for
     * one another.
     */
    window.Alpine.data('cmsMediaPicker', (config = {}) => {
        const core = cmsMediaGridCore(config);

        return {
            ...core,

            multiple: !!config.multiple,
            target: config.target || '',
            scope: config.scope || '',
            modal: config.modal || 'cms-media-picker',
            treeOpen: false,
            /* The tiles of what is chosen, kept beside the identifiers: a file
               preselected by the server, or one chosen before a search
               narrowed the grid, is no longer among `items`, and the answer
               has to carry its name and its thumbnail all the same. */
            chosen: {},

            init() {
                this.folder = config.startFolder === undefined ? null : config.startFolder;
                this.sort = this.restore('sort', 'newest');

                if (config.startSelected) {
                    this.selection = [config.startSelected.id];
                    this.chosen = {[config.startSelected.id]: config.startSelected};
                }

                this.reload();
            },

            /* ------------------------------------------------- the listing */

            /** The kinds the field takes travel on every page of the grid, and
             *  the chip narrows that list instead of widening it: the server
             *  unites `kind` with `kinds[]`, so a picker for pictures sends
             *  one of the two and never both. */
            params(cursor) {
                const params = core.params.call(this, cursor);

                params.delete('kind');

                const kinds = this.kind !== '' ? [this.kind] : this.allowedKinds;

                kinds.forEach(kind => params.append('kinds[]', kind));

                return params;
            },

            selectFolder(id) {
                this.treeOpen = false;
                this.remember(id);
                core.selectFolder.call(this, id);
            },

            remember(id) {
                if (this.scope === '') {
                    return;
                }

                try {
                    if (id === null) {
                        localStorage.removeItem(PICKER_FOLDER + this.scope);
                    } else {
                        localStorage.setItem(PICKER_FOLDER + this.scope, String(id));
                    }
                } catch (e) {
                    // A browser that refuses storage still picks files.
                }
            },

            /* ------------------------------------------------- the choosing */

            /** A click chooses. The details panel of the section is not opened
             *  here at all: a dialog that asks for one file has no room for a
             *  second column, and «Открыть в медиатеке» is where the rest of
             *  it lives. */
            openDetails(item, event) {
                if (this.multiple && event && (event.shiftKey || event.metaKey || event.ctrlKey)) {
                    const index = this.items.findIndex(row => row.id === item.id);

                    core.toggle.call(this, item, index, event);
                    this.keep(item);

                    return;
                }

                this.choose(item);
            },

            toggle(item, index, event) {
                if (!this.multiple) {
                    this.choose(item);

                    return;
                }

                core.toggle.call(this, item, index, event);
                this.keep(item);
            },

            choose(item) {
                this.selection = this.multiple && this.isSelected(item.id)
                    ? this.selection.filter(id => id !== item.id)
                    : (this.multiple ? this.selection.concat([item.id]) : [item.id]);

                this.keep(item);
            },

            keep(item) {
                this.chosen = {...this.chosen, [item.id]: item};
            },

            confirmTile(item) {
                this.choose(item);
                this.pick();
            },

            onGridKey(event) {
                if (event.key === 'Enter') {
                    const tiles = Array.from(this.$root.querySelectorAll('.cms-tile'));
                    const current = tiles.indexOf(document.activeElement);

                    if (current !== -1 && this.items[current]) {
                        event.preventDefault();
                        this.confirmTile(this.items[current]);

                        return;
                    }
                }

                core.onGridKey.call(this, event);
            },

            /** The answer: the files themselves and not their identifiers, so
             *  the field draws the thumbnail without asking the server again. */
            pick() {
                const items = this.selection
                    .map(id => this.chosen[id] || this.items.find(item => item.id === id))
                    .filter(item => !!item)
                    .map(item => ({
                        id: item.id,
                        url: item.url,
                        thumb_url: item.thumb_url,
                        name: item.name,
                        kind: item.kind,
                        size_human: item.size_human,
                        width: item.width,
                        height: item.height,
                    }));

                if (items.length === 0) {
                    return;
                }

                window.dispatchEvent(new CustomEvent('cms-media-pick', {
                    detail: {target: this.target, items},
                }));

                this.close();
            },

            cancel() {
                this.close();
            },

            /** The modal of the panel has one entry point and it toggles, so
             *  closing a dialog that is already closed opens it again, empty.
             *  The state is read off the component before the event is sent. */
            close() {
                const host = document.querySelector('[data-cms-dialog="' + this.modal + '"]');
                const data = host && window.Alpine ? window.Alpine.$data(host) : null;

                if (!(data && 'open' in data && !data.open)) {
                    window.MoonShine.ui.toggleModal(this.modal);
                }

                /* However it ended — a file taken, «Отмена», Esc — the field
                   that opened it takes the keyboard back. */
                window.dispatchEvent(new CustomEvent('cms-media-close', {
                    detail: {target: this.target},
                }));
            },

            /* ------------------------------------------------- the uploads */

            /** A file that has just landed is the one the editor came for, so
             *  it is chosen as well as shown. The same for the answer to «этот
             *  файл уже есть»: they meant that picture either way. */
            prepend(media) {
                core.prepend.call(this, media);
                this.choose(media);
            },

            useExisting(job) {
                const media = job.duplicate;

                job.state = 'done';
                job.message = '';
                job.duplicate = null;

                if (media) {
                    this.prepend(media);
                }

                this.forget();
            },

            /* Escape is not bound to a close here: the overlay stack of the
               panel closes the topmost dialog already, and a second toggle
               would open it straight back up. The field is still told, so the
               keyboard comes back to the button that opened the dialog. */
            onEscape() {
                window.dispatchEvent(new CustomEvent('cms-media-close', {
                    detail: {target: this.target},
                }));
            },
        };
    });
});

/*
 * The fields that open the library: one picture as one field, and the
 * pictures a record owns. Both stand on a form of the panel, open the dialog
 * above and upload through the same endpoint as the section.
 */
document.addEventListener('alpine:init', () => {
    /*
     * One picture as one field.
     *
     * The thumbnail of what is stored, its name and its size, and the two ways
     * of putting another one there: the library, which opens the one dialog of
     * the page, and a file from the desktop, which goes up the moment it is
     * chosen. Nothing is deferred to the save: one path of upload means one
     * set of rules and rules that cannot be walked around.
     *
     * Which field the dialog was opened by travels with the answer. The
     * address carries a key of this instance and the event carries it back, so
     * five pictures on one card never pick for one another; the key is made
     * here and not on the server because a row of a repeater is renumbered on
     * the client.
     */
    window.Alpine.data('cmsMediaField', (config = {}) => ({
        id: config.id || '',
        preview: config.preview || '',
        name: config.name || '',
        size: config.size || '',
        kind: config.kind || '',
        kinds: config.kinds || [],
        endpoints: config.endpoints || {},
        labels: config.labels || {},
        modal: config.modal || 'cms-media-picker',
        scope: config.scope || '',
        token: config.token || '',
        missing: !!config.missing,
        altOpen: !!config.altOpen,

        key: '',
        over: false,
        busy: false,
        progress: 0,
        error: '',
        duplicate: null,
        pending: null,

        init() {
            this.key = 'cms-media-' + Math.random().toString(36).slice(2, 10);
        },

        // A picture already stored.
        get filled() {
            return this.id !== '';
        },

        note() {
            if (this.busy) {
                return this.labels.uploading || '';
            }

            return this.missing ? this.labels.missing || '' : '';
        },

        /* ---------------------------------------------------- the library */

        openLibrary() {
            if (!this.endpoints.picker) {
                return;
            }

            const url = new URL(this.endpoints.picker, window.location.origin);

            this.kinds.forEach(kind => url.searchParams.append('kinds[]', kind));
            url.searchParams.set('multiple', '0');
            url.searchParams.set('target', this.key);
            url.searchParams.set('scope', this.scope);

            if (this.id !== '') {
                url.searchParams.set('selected', this.id);
            }

            const folder = this.rememberedFolder();

            if (folder !== null) {
                url.searchParams.set('folder', folder);
            }

            window.MoonShine.ui.toggleModal(this.modal, url.toString());
        },

        // Where this place left the editor last time. The dialog writes it,
        // the field reads it: a slider filled from one folder opens in that
        // folder for every slide.
        rememberedFolder() {
            if (this.scope === '') {
                return null;
            }

            try {
                return localStorage.getItem('cms-media-picker:folder:' + this.scope);
            } catch (e) {
                return null;
            }
        },

        chosen(event) {
            const detail = event && event.detail ? event.detail : {};

            if (detail.target !== this.key) {
                return;
            }

            const item = (detail.items || [])[0];

            if (item) {
                this.apply(item);
            }
        },

        // However the dialog ended, the keyboard comes back where it was. The
        // button and not the element that was focused when it opened: an empty
        // field that now holds a picture draws different buttons, and the one
        // that was clicked is gone by then.
        closed(event) {
            const detail = event && event.detail ? event.detail : {};

            if (detail.target !== this.key) {
                return;
            }

            this.$nextTick(() => {
                const buttons = Array.from(this.$el.querySelectorAll('[data-cms-media-open]'));
                const shown = buttons.find(button => button.offsetParent !== null);

                if (shown) {
                    shown.focus();
                }
            });
        },

        apply(item) {
            this.id = item.id;
            this.preview = item.thumb_url || (item.kind === 'vector' ? item.url : '');
            this.name = item.name;
            this.size = item.size_human || '';
            this.kind = item.kind || '';
            this.missing = false;
            this.error = '';
            this.duplicate = null;
            this.announce();
        },

        clear() {
            this.id = '';
            this.preview = '';
            this.name = '';
            this.size = '';
            this.kind = '';
            this.missing = false;
            this.error = '';
            this.duplicate = null;
            this.announce();
        },

        // The hidden input is what the form submits, and a form that tracks
        // its unsaved changes measures itself by what it would submit: a value
        // put there by script has to say so itself.
        announce() {
            this.$nextTick(() => {
                if (this.$refs.idInput) {
                    this.$refs.idInput.dispatchEvent(new Event('change', {bubbles: true}));
                }
            });
        },

        /* --------------------------------------------------- the desktop */

        upload() {
            this.$refs.file.click();
        },

        picked(event) {
            const file = event.target.files ? event.target.files[0] : null;

            event.target.value = '';

            if (file) {
                this.take(file, false);
            }
        },

        dropped(event) {
            this.over = false;

            const file = event.dataTransfer && event.dataTransfer.files
                ? event.dataTransfer.files[0]
                : null;

            if (file) {
                this.take(file, false);
            }
        },

        useExisting() {
            const media = this.duplicate;

            this.duplicate = null;
            this.pending = null;

            if (media) {
                this.apply(media);
            }
        },

        uploadCopy() {
            const file = this.pending;

            this.duplicate = null;

            if (file) {
                this.take(file, true);
            }
        },

        // `XMLHttpRequest` and not `fetch` for the one reason the library uses
        // it too: it is the only one of the two that reports how much of a
        // file has gone up.
        take(file, force) {
            if (!this.endpoints.upload) {
                return;
            }

            const data = new FormData();

            data.append('file', file);
            this.kinds.forEach(kind => data.append('allowed_kinds[]', kind));

            const folder = this.rememberedFolder();

            if (folder !== null) {
                data.append('folder', folder);
            }

            if (force) {
                data.append('force', '1');
            }

            this.busy = true;
            this.progress = 0;
            this.error = '';
            this.duplicate = null;
            this.pending = file;

            const request = new XMLHttpRequest();

            request.open('POST', this.endpoints.upload);
            request.setRequestHeader('X-CSRF-TOKEN', this.token);
            request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            request.setRequestHeader('Accept', 'application/json');

            request.upload.onprogress = event => {
                this.progress = event.lengthComputable
                    ? Math.round((event.loaded / event.total) * 100)
                    : 50;
            };

            request.onload = () => {
                this.busy = false;
                this.progress = 100;

                let body = {};

                try {
                    body = JSON.parse(request.responseText || '{}');
                } catch (e) {
                    body = {};
                }

                this.settle(request.status, body);
            };

            request.onerror = () => {
                this.busy = false;
                this.error = this.labels.failed || '';
            };

            request.send(data);
        },

        settle(status, body) {
            if (status >= 400) {
                this.error = body.message || this.labels.failed || '';

                return;
            }

            // The same question the library asks: the bytes are already
            // stored, so either that record is what they meant or they want a
            // second copy of it.
            if (body.duplicate) {
                this.duplicate = body.duplicate;

                return;
            }

            this.pending = null;

            if (body.media) {
                this.apply(body.media);
            }
        },
    }));

    /*
     * The pictures a record owns.
     *
     * The list is the state: every file in it is one hidden input named
     * `media_attachments[<role>][<language>][]`, in the order they are drawn,
     * so the order of a gallery is the order of the inputs and the form POST
     * carries the whole of it. Nothing is written anywhere until the form is
     * saved, and nothing is written here at all — the rows of
     * `cms_media_usages` are the business of the resource.
     *
     * Files come from the same two places the field of one picture takes them
     * from: the one dialog of the page, and the desktop, which goes up through
     * the endpoint of the library the moment a file is chosen.
     */
    window.Alpine.data('cmsMediaAttachments', (config = {}) => ({
        items: (config.items || []).map(item => ({
            id: item.id,
            name: item.name || '',
            size_human: item.size_human || '',
            kind: item.kind || '',
            thumb_url: item.thumb_url || '',
        })),
        name: config.name || '',
        multiple: !!config.multiple,
        max: config.max === undefined || config.max === null ? null : config.max,
        kinds: config.kinds || [],
        endpoints: config.endpoints || {},
        labels: config.labels || {},
        modal: config.modal || 'cms-media-picker',
        scope: config.scope || '',
        token: config.token || '',

        key: '',
        busy: false,
        progress: 0,
        error: '',
        duplicate: null,
        pending: null,
        queue: [],
        dragged: null,
        over: null,

        init() {
            this.key = 'cms-attachments-' + Math.random().toString(36).slice(2, 10);

            /* The list the server wrote is Alpine's from here on, and two
               copies of the same hidden inputs would submit every uuid twice. */
            if (this.$refs.initial) {
                this.$refs.initial.remove();
            }

            this.$nextTick(() => this.syncEmpty());
        },

        /* --------------------------------------------------------- the room */

        full() {
            return this.multiple && this.max !== null && this.items.length >= this.max;
        },

        room() {
            if (!this.multiple) {
                return 1;
            }

            return this.max === null ? Number.MAX_SAFE_INTEGER : Math.max(0, this.max - this.items.length);
        },

        add(item) {
            if (!item || !item.id) {
                return;
            }

            const file = {
                id: item.id,
                name: item.name || '',
                size_human: item.size_human || '',
                kind: item.kind || '',
                /* A vector has no rendition and is drawn as itself, the way
                   the grid of the library draws it. */
                thumb_url: item.thumb_url || (item.kind === 'vector' ? item.url || '' : ''),
            };

            if (!this.multiple) {
                this.items = [file];
                this.settled();

                return;
            }

            /* The same file twice is one file: a gallery showing one picture
               in two places is a slip of the hand and never an order. */
            if (this.items.some(row => row.id === file.id) || this.full()) {
                return;
            }

            this.items = this.items.concat([file]);
            this.settled();
        },

        remove(index) {
            this.items = this.items.filter((item, position) => position !== index);
            this.settled();
        },

        /* «Убрать Логотип клуба» and not «Убрать»: the label of the button is
           the same word on every row, and a screen reader reads the rows one
           at a time. */
        removeLabel(item) {
            return String(this.labels.remove_named || '').replace(':name', item.name || '');
        },

        settled() {
            this.error = '';
            this.$nextTick(() => this.syncEmpty());
        },

        /* Whether the language of this box still has nothing in it, the way
           the switch of the form marks a translation nobody filled: the uuids
           travel in hidden inputs, and a hidden input counts for nothing when
           the form walks the box itself. */
        syncEmpty() {
            const wrapper = this.$el.closest('[data-cms-locale]');

            if (!wrapper) {
                return;
            }

            if (this.items.length > 0) {
                wrapper.removeAttribute('data-cms-empty');
            } else {
                wrapper.setAttribute('data-cms-empty', '');
            }
        },

        /* -------------------------------------------------------- the order */

        startDrag(index, event) {
            if (!this.multiple) {
                return;
            }

            this.dragged = index;

            if (event && event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', String(index));
            }
        },

        dragOver(index) {
            if (this.dragged !== null) {
                this.over = index;
            }
        },

        drop(index) {
            if (this.dragged === null || this.dragged === index) {
                this.endDrag();

                return;
            }

            const items = this.items.slice();
            const moved = items.splice(this.dragged, 1)[0];

            items.splice(index, 0, moved);
            this.items = items;
            this.endDrag();
        },

        endDrag() {
            this.dragged = null;
            this.over = null;
        },

        /* ------------------------------------------------------ the library */

        openLibrary() {
            if (!this.endpoints.picker) {
                return;
            }

            const url = new URL(this.endpoints.picker, window.location.origin);

            this.kinds.forEach(kind => url.searchParams.append('kinds[]', kind));
            url.searchParams.set('multiple', this.multiple ? '1' : '0');
            url.searchParams.set('target', this.key);
            url.searchParams.set('scope', this.scope);

            if (!this.multiple && this.items.length > 0) {
                url.searchParams.set('selected', this.items[0].id);
            }

            const folder = this.rememberedFolder();

            if (folder !== null) {
                url.searchParams.set('folder', folder);
            }

            window.MoonShine.ui.toggleModal(this.modal, url.toString());
        },

        /* Where this place left the editor last time. The dialog writes it,
           the field reads it: a gallery filled out of one folder opens in that
           folder again. */
        rememberedFolder() {
            if (this.scope === '') {
                return null;
            }

            try {
                return localStorage.getItem('cms-media-picker:folder:' + this.scope);
            } catch (e) {
                return null;
            }
        },

        chosen(event) {
            const detail = event && event.detail ? event.detail : {};

            if (detail.target !== this.key) {
                return;
            }

            (detail.items || []).slice(0, this.room()).forEach(item => this.add(item));
        },

        /* However the dialog ended, the keyboard comes back where it was. */
        closed(event) {
            const detail = event && event.detail ? event.detail : {};

            if (detail.target !== this.key) {
                return;
            }

            this.$nextTick(() => {
                const button = this.$el.querySelector('[data-cms-media-open]');

                if (button) {
                    button.focus();
                }
            });
        },

        /* ------------------------------------------------------ the desktop */

        upload() {
            this.$refs.file.click();
        },

        picked(event) {
            const files = Array.from(event.target.files || []);

            event.target.value = '';
            this.enqueue(files);
        },

        enqueue(files) {
            const room = this.room();

            if (room <= 0) {
                this.error = this.labels.limit || '';

                return;
            }

            this.queue = this.queue.concat(files.slice(0, room));
            this.pump();
        },

        /* One file at a time. A gallery is a handful of pictures and never a
           drop of thirty, and one bar is easier to read than three. */
        pump() {
            if (this.busy || this.duplicate) {
                return;
            }

            const file = this.queue.shift();

            if (file) {
                this.take(file, false);
            }
        },

        useExisting() {
            const media = this.duplicate;

            this.duplicate = null;
            this.pending = null;

            if (media) {
                this.add(media);
            }

            this.pump();
        },

        uploadCopy() {
            const file = this.pending;

            this.duplicate = null;

            if (file) {
                this.take(file, true);
            }
        },

        /* `XMLHttpRequest` and not `fetch` for the one reason the library uses
           it too: it is the only one of the two that reports how much of a
           file has gone up. */
        take(file, force) {
            if (!this.endpoints.upload) {
                return;
            }

            const data = new FormData();

            data.append('file', file);
            this.kinds.forEach(kind => data.append('allowed_kinds[]', kind));

            const folder = this.rememberedFolder();

            if (folder !== null) {
                data.append('folder', folder);
            }

            if (force) {
                data.append('force', '1');
            }

            this.busy = true;
            this.progress = 0;
            this.error = '';
            this.duplicate = null;
            this.pending = file;

            const request = new XMLHttpRequest();

            request.open('POST', this.endpoints.upload);
            request.setRequestHeader('X-CSRF-TOKEN', this.token);
            request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            request.setRequestHeader('Accept', 'application/json');

            request.upload.onprogress = event => {
                this.progress = event.lengthComputable
                    ? Math.round((event.loaded / event.total) * 100)
                    : 50;
            };

            request.onload = () => {
                this.busy = false;
                this.progress = 100;

                let body = {};

                try {
                    body = JSON.parse(request.responseText || '{}');
                } catch (e) {
                    body = {};
                }

                this.settle(request.status, body);
                this.pump();
            };

            request.onerror = () => {
                this.busy = false;
                this.error = this.labels.failed || '';
                this.queue = [];
            };

            request.send(data);
        },

        settle(status, body) {
            if (status >= 400) {
                this.error = body.message || this.labels.failed || '';
                this.queue = [];

                return;
            }

            /* The same question the library asks: the bytes are already
               stored, so either that record is what they meant or they want a
               second copy of it. */
            if (body.duplicate) {
                this.duplicate = body.duplicate;

                return;
            }

            this.pending = null;

            if (body.media) {
                this.add(body.media);
            }
        },
    }));
});
