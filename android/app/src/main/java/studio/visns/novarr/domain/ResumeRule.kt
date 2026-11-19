package studio.visns.novarr.domain

import studio.visns.novarr.api.BulkReadRequest
import studio.visns.novarr.api.ChapterListItem
import studio.visns.novarr.api.ChapterRef
import studio.visns.novarr.api.ContinueItem
import studio.visns.novarr.store.PendingWrite
import studio.visns.novarr.store.WriteKind

/**
 * Where "Continue" opens a series: the rule every device shares (the
 * server's `App\Services\ResumePoint`, which answers `resume_chapter_id`;
 * the iOS app's `ResumeRule`), applied here to the server's answer plus this
 * device's reading that has not reached the server yet (the unsent writes in
 * the queue), so Continue is right offline too and agrees with what the
 * server will answer once the writes arrive.
 *
 * Over the downloaded chapters in reading order:
 * 1. The anchor is this device's most recent unsent reading: the chapter of
 *    the newest pending write (ties: the furthest in reading order) that is
 *    now read, or unread with a position of 1 % or more. A chapter marked
 *    unread, or opened at 0 %, is not reading.
 * 2. No anchor: the server's chapter (it applied the same rule); without
 *    one, the first downloaded unread chapter.
 * 3. An unfinished anchor is resumed: unread at 1–99 %, or read below 90 %
 *    (a read chapter being re-read; the web reader marks read on open).
 * 4. Otherwise the first downloaded unread chapter after it; none (caught
 *    up): the last downloaded chapter, as the server answers.
 * Earlier unread chapters and older part-read ones never pull back.
 *
 * Pure: no I/O, so it is unit-tested directly (ResumeRuleTest).
 */
object ResumeRule {
    /** A read chapter below this position is still being read. */
    const val READ_UNFINISHED_BELOW = 90

    /** Rule 3 for one chapter's state. */
    fun isUnfinished(read: Boolean, progress: Int?): Boolean {
        if (progress == null) return false
        return if (read) progress < READ_UNFINISHED_BELOW else progress in 1..99
    }

    /**
     * The chapter "Continue" opens.
     *
     * @param chapters the chapter list in reading order, with the read state this device knows.
     * @param serverId the server's `resume_chapter_id`.
     * @param pending this series' unsent writes, oldest first (queue order).
     */
    fun resolve(chapters: List<ChapterListItem>, serverId: Int?, pending: List<PendingWrite>): Int? {
        if (chapters.isEmpty()) return serverId
        val state = Replay(chapters, pending)

        // The anchor: the newest touched chapter that is now reading (ties: the furthest).
        var anchorIndex = -1
        var anchorAt = Long.MIN_VALUE
        for ((id, at) in state.touched) {
            val index = state.position[id] ?: continue
            if (!chapters[index].downloaded) continue
            if (!state.isRead(id) && (state.progress(id) ?: 0) < 1) continue
            if (anchorIndex >= 0 && (at < anchorAt || (at == anchorAt && index < anchorIndex))) continue
            anchorIndex = index
            anchorAt = at
        }

        if (anchorIndex < 0) {
            return serverId ?: chapters.firstOrNull { it.downloaded && !state.isRead(it.id) }?.id
        }
        val anchor = chapters[anchorIndex]
        if (isUnfinished(state.isRead(anchor.id), state.progress(anchor.id))) return anchor.id
        val after = chapters.subList(anchorIndex + 1, chapters.size).filter { it.downloaded }
        return (after.firstOrNull { !state.isRead(it.id) } ?: after.lastOrNull() ?: anchor).id
    }

    /**
     * Whether the reader has begun this series, for the Start / Continue
     * label: the server counts read chapters, or (with this device's unsent
     * writes replayed) any chapter is read or has a position of 1 % or more.
     */
    fun hasStarted(chapters: List<ChapterListItem>, serverReadCount: Int, pending: List<PendingWrite>): Boolean {
        if (serverReadCount > 0) return true
        val state = Replay(chapters, pending)
        return chapters.any { state.isRead(it.id) || (state.progress(it.id) ?: 0) >= 1 }
    }

    /**
     * One Home Continue item with the chapter [resolve] picks from the cached
     * chapter list and this series' unsent writes; unchanged when nothing is
     * unsent or the rule can't tell.
     */
    fun adjusted(item: ContinueItem, chapters: List<ChapterListItem>, unsent: List<PendingWrite>): ContinueItem {
        if (unsent.isEmpty()) return item
        val serverId = item.novel.resumeChapterId ?: item.chapter.id
        val id = resolve(chapters, serverId, unsent) ?: return item
        if (id == item.chapter.id) return item
        val chapter = chapters.firstOrNull { it.id == id } ?: return item
        return item.copy(
            novel = item.novel.copy(resumeChapterId = chapter.id),
            chapter = ChapterRef(id = chapter.id, label = chapter.label, chapter = chapter.chapter, book = chapter.book, downloaded = chapter.downloaded),
        )
    }

    /** The chapter list's read state with the unsent writes applied, oldest first, as the server will apply them. */
    private class Replay(private val chapters: List<ChapterListItem>, pending: List<PendingWrite>) {
        val position = HashMap<Int, Int>()
        private val read = HashMap<Int, Boolean>()
        private val progress = HashMap<Int, Int?>()

        /** Chapters the writes touched, with the time of the newest write touching each. */
        val touched = LinkedHashMap<Int, Long>()

        init {
            chapters.forEachIndexed { index, chapter ->
                position.putIfAbsent(chapter.id, index)
                read[chapter.id] = chapter.read
                progress[chapter.id] = chapter.readProgress
            }
            for (write in pending) apply(write)
        }

        fun isRead(id: Int): Boolean = read[id] ?: false

        fun progress(id: Int): Int? = progress[id]

        private fun set(id: Int, isRead: Boolean, value: Int?) {
            if (id !in position) return
            read[id] = isRead
            progress[id] = value
        }

        private fun apply(write: PendingWrite) {
            val at = write.recordedAt
            when (val kind = write.kind) {
                is WriteKind.Progress -> {
                    val id = kind.chapterId
                    if (id !in position) return
                    if (kind.lookup) {
                        // A look-up's position is never stored; its read mark is,
                        // and leaves a chapter already read as it was.
                        if (kind.read && read[id] == false) {
                            read[id] = true
                            touched[id] = at
                        }
                        return
                    }
                    if (kind.read) read[id] = true
                    kind.progress?.let { progress[id] = it }
                    touched[id] = at
                }
                is WriteKind.SetRead -> {
                    if (kind.chapterId !in position) return
                    set(kind.chapterId, kind.read, if (kind.read) 100 else null)
                    touched[kind.chapterId] = at
                }
                is WriteKind.BulkRead -> for (id in bulkIds(kind.request)) {
                    if (id !in position) continue
                    set(id, kind.request.read, if (kind.request.read) 100 else null)
                    touched[id] = at
                }
                is WriteKind.CreateHighlight -> Unit
            }
        }

        /** The chapters a bulk mark covers, as the server selects them. */
        private fun bulkIds(request: BulkReadRequest): List<Int> = when (request.scope) {
            BulkReadRequest.ALL -> chapters.map { it.id }
            BulkReadRequest.UP_TO, BulkReadRequest.FROM -> {
                val index = request.anchorId?.let { position[it] }
                when {
                    index == null -> emptyList()
                    request.scope == BulkReadRequest.UP_TO -> chapters.subList(0, index + 1).map { it.id }
                    else -> chapters.subList(index, chapters.size).map { it.id }
                }
            }
            else -> request.ids.orEmpty()
        }
    }
}
