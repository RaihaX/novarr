#!/bin/zsh

# =============================================================================
# Novarr Docker Build Script
# =============================================================================
# Builds and tags Docker images with version control
# Usage: ./docker-build.sh [version] [options]
# =============================================================================

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Configuration
IMAGE_NAME="novarr"
DOCKERFILE="Dockerfile"
BUILD_CONTEXT="."
TIMESTAMP=$(date +"%Y%m%d-%H%M%S")

# Default values
VERSION="${1:-latest}"
NO_CACHE=false
PUSH=false
MULTI_ARCH=false

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --no-cache)
            NO_CACHE=true
            shift
            ;;
        --push)
            PUSH=true
            shift
            ;;
        --multi-arch)
            MULTI_ARCH=true
            shift
            ;;
        -h|--help)
            echo "Usage: ./docker-build.sh [version] [options]"
            echo ""
            echo "Options:"
            echo "  --no-cache    Build without using cache"
            echo "  --push        Push image to registry after build"
            echo "  --multi-arch  Build for multiple architectures (AMD64/ARM64)"
            echo "  -h, --help    Show this help message"
            echo ""
            echo "Examples:"
            echo "  ./docker-build.sh                    # Build with 'latest' tag"
            echo "  ./docker-build.sh v1.0.0             # Build with version tag"
            echo "  ./docker-build.sh v1.0.0 --no-cache  # Clean build with version"
            exit 0
            ;;
        *)
            if [[ ! "$1" =~ ^-- ]]; then
                VERSION="$1"
            fi
            shift
            ;;
    esac
done

# Functions
print_header() {
    echo ""
    echo -e "${CYAN}========================================${NC}"
    echo -e "${CYAN}  $1${NC}"
    echo -e "${CYAN}========================================${NC}"
    echo ""
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_info() {
    echo -e "${BLUE}ℹ $1${NC}"
}

check_docker() {
    if ! command -v docker &> /dev/null; then
        print_error "Docker is not installed or not in PATH"
        exit 1
    fi

    if ! docker info &> /dev/null; then
        print_error "Docker daemon is not running"
        exit 1
    fi

    print_success "Docker daemon is available"
}

check_dockerfile() {
    if [[ ! -f "$DOCKERFILE" ]]; then
        print_error "Dockerfile not found at: $DOCKERFILE"
        exit 1
    fi

    print_success "Dockerfile found"
}

check_git_status() {
    if command -v git &> /dev/null && [[ -d ".git" ]]; then
        if [[ -n $(git status --porcelain) ]]; then
            print_warning "There are uncommitted changes in the repository"
            echo ""
            git status --short
            echo ""
        else
            print_success "Git working directory is clean"
        fi
    fi
}

get_git_info() {
    if command -v git &> /dev/null && [[ -d ".git" ]]; then
        GIT_COMMIT=$(git rev-parse --short HEAD 2>/dev/null || echo "unknown")
        GIT_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "unknown")
    else
        GIT_COMMIT="unknown"
        GIT_BRANCH="unknown"
    fi
}

build_image() {
    local build_args=""

    # Add build arguments
    build_args="--build-arg BUILD_DATE=$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
    build_args="$build_args --build-arg VCS_REF=$GIT_COMMIT"
    build_args="$build_args --build-arg VERSION=$VERSION"

    # Add no-cache flag if requested
    if [[ "$NO_CACHE" == "true" ]]; then
        build_args="$build_args --no-cache"
        print_info "Building without cache"
    fi

    # Build command
    local build_cmd="docker build $build_args -t ${IMAGE_NAME}:${VERSION} -t ${IMAGE_NAME}:${TIMESTAMP} -t ${IMAGE_NAME}:latest -f $DOCKERFILE $BUILD_CONTEXT"

    if [[ "$MULTI_ARCH" == "true" ]]; then
        print_info "Building for multiple architectures (AMD64, ARM64)"
        build_cmd="docker buildx build --platform linux/amd64,linux/arm64 $build_args -t ${IMAGE_NAME}:${VERSION} -t ${IMAGE_NAME}:${TIMESTAMP} -t ${IMAGE_NAME}:latest -f $DOCKERFILE $BUILD_CONTEXT"

        if [[ "$PUSH" == "true" ]]; then
            build_cmd="$build_cmd --push"
        else
            build_cmd="$build_cmd --load"
        fi
    fi

    print_info "Building image: ${IMAGE_NAME}:${VERSION}"
    echo ""

    # Track build time
    local start_time=$(date +%s)

    # Execute build
    if eval $build_cmd; then
        local end_time=$(date +%s)
        local duration=$((end_time - start_time))

        echo ""
        print_success "Build completed successfully in ${duration}s"
        return 0
    else
        print_error "Build failed"
        return 1
    fi
}

push_image() {
    if [[ "$PUSH" == "true" ]] && [[ "$MULTI_ARCH" == "false" ]]; then
        print_info "Pushing images to registry..."

        docker push ${IMAGE_NAME}:${VERSION}
        docker push ${IMAGE_NAME}:${TIMESTAMP}
        docker push ${IMAGE_NAME}:latest

        print_success "Images pushed successfully"
    fi
}

show_summary() {
    print_header "Build Summary"

    # Get image size
    local image_size=$(docker images ${IMAGE_NAME}:${VERSION} --format "{{.Size}}" 2>/dev/null || echo "unknown")
    local image_id=$(docker images ${IMAGE_NAME}:${VERSION} --format "{{.ID}}" 2>/dev/null || echo "unknown")

    echo -e "Image Name:     ${GREEN}${IMAGE_NAME}${NC}"
    echo -e "Image ID:       ${CYAN}${image_id}${NC}"
    echo -e "Image Size:     ${YELLOW}${image_size}${NC}"
    echo ""
    echo -e "Tags created:"
    echo -e "  - ${GREEN}${IMAGE_NAME}:${VERSION}${NC}"
    echo -e "  - ${GREEN}${IMAGE_NAME}:${TIMESTAMP}${NC}"
    echo -e "  - ${GREEN}${IMAGE_NAME}:latest${NC}"
    echo ""
    echo -e "Git Commit:     ${CYAN}${GIT_COMMIT}${NC}"
    echo -e "Git Branch:     ${CYAN}${GIT_BRANCH}${NC}"
    echo ""

    if [[ "$PUSH" == "true" ]]; then
        print_success "Images have been pushed to registry"
    else
        print_info "Images are available locally (use --push to push to registry)"
    fi

    # Export tag info for other scripts
    echo ""
    print_info "To use this image with docker-compose, run:"
    echo -e "  ${CYAN}NOVARR_TAG=${VERSION} docker compose up -d${NC}"
    echo ""
}

# Write tag to file for other scripts to consume
write_tag_file() {
    local tag_file=".docker-tag"
    echo "NOVARR_IMAGE=${IMAGE_NAME}" > "$tag_file"
    echo "NOVARR_TAG=${VERSION}" >> "$tag_file"
    echo "NOVARR_TIMESTAMP=${TIMESTAMP}" >> "$tag_file"
    echo "NOVARR_GIT_COMMIT=${GIT_COMMIT}" >> "$tag_file"
    print_info "Tag info written to ${tag_file}"
}

# Main execution
main() {
    print_header "Novarr Docker Build"

    echo -e "Version:        ${GREEN}${VERSION}${NC}"
    echo -e "Timestamp:      ${CYAN}${TIMESTAMP}${NC}"
    echo -e "No Cache:       ${YELLOW}${NO_CACHE}${NC}"
    echo -e "Push:           ${YELLOW}${PUSH}${NC}"
    echo -e "Multi-Arch:     ${YELLOW}${MULTI_ARCH}${NC}"
    echo ""

    print_header "Pre-build Checks"

    check_docker
    check_dockerfile
    check_git_status
    get_git_info

    print_header "Building Image"

    if build_image; then
        push_image
        write_tag_file
        show_summary

        echo ""
        print_success "Build process completed successfully!"
        exit 0
    else
        echo ""
        print_error "Build process failed!"
        exit 1
    fi
}

# Run main function
main
