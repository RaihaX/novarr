import SwiftUI
import UIKit

/// Text the reader wants to highlight.
struct HighlightRequest: Identifiable {
    let id = UUID()
    /// The selection (Scroll mode) or the long-pressed paragraph (Pages mode).
    var text: String
    /// Pages mode: the paragraph is shown selectable so it can be narrowed;
    /// without a selection the whole paragraph is saved.
    var narrowable: Bool
}

/// The excerpt, an optional note and Save. Saving goes through the write
/// queue, so it works offline; the server's length limits are checked here.
struct HighlightSheet: View {
    let request: HighlightRequest
    var onSave: (_ excerpt: String, _ note: String?) async -> Bool

    @Environment(\.dismiss) private var dismiss
    @State private var selection = ""
    @State private var note = ""
    @State private var saving = false
    @State private var failed = false

    private var excerpt: String {
        let narrowed = selection.trimmingCharacters(in: .whitespacesAndNewlines)
        return narrowed.isEmpty ? request.text.trimmingCharacters(in: .whitespacesAndNewlines) : narrowed
    }

    private var excerptLength: Int { HighlightDraft.length(excerpt) }
    private var noteLength: Int { HighlightDraft.length(note.trimmingCharacters(in: .whitespacesAndNewlines)) }
    private var canSave: Bool {
        !saving && !excerpt.isEmpty && excerptLength <= HighlightDraft.maxLength && noteLength <= HighlightDraft.maxLength
    }

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    if request.narrowable {
                        SelectableExcerpt(text: request.text, selection: $selection)
                    } else {
                        Text(excerpt)
                            .font(.literata(.body))
                            .textSelection(.enabled)
                    }
                } header: {
                    Text("Excerpt")
                } footer: {
                    VStack(alignment: .leading, spacing: 4) {
                        if request.narrowable {
                            Text(selection.isEmpty
                                 ? "Select part of the paragraph to highlight just that; otherwise all of it is saved."
                                 : "Only the selected text is saved.")
                        }
                        if excerptLength > HighlightDraft.maxLength {
                            Text("Highlights can be up to \(HighlightDraft.maxLength.formatted()) characters; this is \(excerptLength.formatted()). Select a shorter passage.")
                                .foregroundStyle(.red)
                        }
                    }
                }
                Section {
                    TextField("Add a note", text: $note, axis: .vertical)
                        .lineLimit(2...8)
                } header: {
                    Text("Note (optional)")
                } footer: {
                    if noteLength > HighlightDraft.maxLength {
                        Text("Notes can be up to \(HighlightDraft.maxLength.formatted()) characters; this is \(noteLength.formatted()).")
                            .foregroundStyle(.red)
                    }
                }
            }
            .navigationTitle("Highlight")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") {
                        saving = true
                        Task {
                            let trimmed = note.trimmingCharacters(in: .whitespacesAndNewlines)
                            if await onSave(excerpt, trimmed.isEmpty ? nil : trimmed) {
                                dismiss()
                            } else {
                                saving = false
                                failed = true
                            }
                        }
                    }
                    .disabled(!canSave)
                }
            }
            .alert("Couldn't save the highlight", isPresented: $failed) {
                Button("OK", role: .cancel) {}
            }
        }
        .presentationDetents([.medium, .large])
    }
}

/// The paragraph as selectable text; reports the selection as it changes.
private struct SelectableExcerpt: UIViewRepresentable {
    let text: String
    @Binding var selection: String

    func makeCoordinator() -> Coordinator { Coordinator(selection: $selection) }

    func makeUIView(context: Context) -> UITextView {
        let view = UITextView()
        view.isEditable = false
        view.isSelectable = true
        view.isScrollEnabled = false
        view.backgroundColor = .clear
        view.textContainerInset = .zero
        view.textContainer.lineFragmentPadding = 0
        view.font = UIFontMetrics(forTextStyle: .body).scaledFont(for: ReaderFont.literata.uiFont(size: 17))
        view.adjustsFontForContentSizeCategory = true
        view.textColor = .label
        view.text = text
        view.delegate = context.coordinator
        view.setContentCompressionResistancePriority(.defaultLow, for: .horizontal)
        return view
    }

    func updateUIView(_ view: UITextView, context: Context) {
        context.coordinator.selection = $selection
        if view.text != text { view.text = text }
    }

    func sizeThatFits(_ proposal: ProposedViewSize, uiView: UITextView, context: Context) -> CGSize? {
        guard let width = proposal.width, width.isFinite, width > 0 else { return nil }
        let size = uiView.sizeThatFits(CGSize(width: width, height: .greatestFiniteMagnitude))
        return CGSize(width: width, height: ceil(size.height))
    }

    @MainActor
    final class Coordinator: NSObject, UITextViewDelegate {
        var selection: Binding<String>

        init(selection: Binding<String>) {
            self.selection = selection
        }

        func textViewDidChangeSelection(_ textView: UITextView) {
            let range = textView.selectedRange
            selection.wrappedValue = range.length > 0 ? (textView.text as NSString).substring(with: range) : ""
        }
    }
}
