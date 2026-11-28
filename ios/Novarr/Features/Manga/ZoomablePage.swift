import SwiftUI
import UIKit

/// A page that zooms like Photos: pinch, pan while zoomed, double-tap to
/// zoom in at the tapped point (and out again). A single tap reports where
/// it landed across the window, for the tap zones. At 1× the scroll view
/// has nothing to scroll, so horizontal swipes reach the pager underneath.
struct ZoomablePage<Content: View>: UIViewRepresentable {
    /// Called with the tap's x in the window and the window's width.
    var onTap: (_ point: CGPoint, _ screen: CGSize) -> Void
    @ViewBuilder var content: () -> Content

    func makeCoordinator() -> Coordinator { Coordinator() }

    func makeUIView(context: Context) -> ZoomScrollView {
        let host = UIHostingController(rootView: AnyView(content()))
        host.view.backgroundColor = .clear
        host.sizingOptions = []
        let view = ZoomScrollView(hosted: host.view)
        context.coordinator.host = host
        view.onTap = onTap
        return view
    }

    func updateUIView(_ view: ZoomScrollView, context: Context) {
        context.coordinator.host?.rootView = AnyView(content())
        view.onTap = onTap
    }

    final class Coordinator {
        var host: UIHostingController<AnyView>?
    }
}

final class ZoomScrollView: UIScrollView, UIScrollViewDelegate {
    private let hosted: UIView
    var onTap: ((CGPoint, CGSize) -> Void)?

    /// Double-tap zooms to this scale.
    static let doubleTapScale: CGFloat = 2.5

    init(hosted: UIView) {
        self.hosted = hosted
        super.init(frame: .zero)
        delegate = self
        minimumZoomScale = 1
        maximumZoomScale = 5
        showsVerticalScrollIndicator = false
        showsHorizontalScrollIndicator = false
        contentInsetAdjustmentBehavior = .never
        decelerationRate = .fast
        backgroundColor = .clear
        addSubview(hosted)

        let double = UITapGestureRecognizer(target: self, action: #selector(doubleTapped(_:)))
        double.numberOfTapsRequired = 2
        let single = UITapGestureRecognizer(target: self, action: #selector(tapped(_:)))
        single.require(toFail: double)
        addGestureRecognizer(double)
        addGestureRecognizer(single)
    }

    @available(*, unavailable)
    required init?(coder: NSCoder) { fatalError("init(coder:) is not used") }

    override func layoutSubviews() {
        super.layoutSubviews()
        // At 1× the page is exactly the view; zooming scales it from there.
        if zoomScale == 1, hosted.frame.size != bounds.size {
            hosted.frame = CGRect(origin: .zero, size: bounds.size)
            contentSize = bounds.size
        }
    }

    func viewForZooming(in scrollView: UIScrollView) -> UIView? { hosted }

    @objc private func doubleTapped(_ gesture: UITapGestureRecognizer) {
        if zoomScale > minimumZoomScale + 0.01 {
            setZoomScale(minimumZoomScale, animated: true)
        } else {
            let point = gesture.location(in: hosted)
            let size = CGSize(width: bounds.width / Self.doubleTapScale, height: bounds.height / Self.doubleTapScale)
            zoom(to: CGRect(x: point.x - size.width / 2, y: point.y - size.height / 2,
                            width: size.width, height: size.height), animated: true)
        }
    }

    @objc private func tapped(_ gesture: UITapGestureRecognizer) {
        let host = window ?? self
        let point = gesture.location(in: host)
        onTap?(point, host.bounds.size)
    }
}
