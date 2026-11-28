import SwiftUI

// OWNER: categories / Home / Library area. Replace the placeholder views.

extension View {
    /// Want to read, a category's folder, Manage categories, Settings › Home.
    func listsDestinations() -> some View {
        navigationDestination(for: WantToReadRoute.self) { _ in
            Text("Want to read")
        }
        .navigationDestination(for: FolderRoute.self) { route in
            Text("Category \(route.id)")
        }
        .navigationDestination(for: CategoriesRoute.self) { _ in
            Text("Manage categories")
        }
        .navigationDestination(for: HomeSettingsRoute.self) { _ in
            Text("Home settings")
        }
    }
}

/// The series screen's category controls: the heart (Favourites), Want to
/// read and "Categories…". Shown under the primary button.
struct NovelCategoryActions: View {
    let summary: NovelSummary

    var body: some View { EmptyView() }
}

/// Settings rows for this area (Home, Manage categories).
struct SettingsListsSection: View {
    var body: some View { EmptyView() }
}
