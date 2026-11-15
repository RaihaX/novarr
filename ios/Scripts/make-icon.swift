#!/usr/bin/env swift
// Renders the Novarr app icon: the "Serial" mark (four flat bars, 22/22/15/8
// units long on a 32-unit grid, 4 units tall, 6.5-unit pitch) in white on
// indigo. Usage: swift Scripts/make-icon.swift [output.png]
import CoreGraphics
import Foundation
import ImageIO
import UniformTypeIdentifiers

let size = 1024
let output = CommandLine.arguments.count > 1
    ? CommandLine.arguments[1]
    : "Novarr/Resources/Assets.xcassets/AppIcon.appiconset/AppIcon-1024.png"

guard let space = CGColorSpace(name: CGColorSpace.sRGB),
      let ctx = CGContext(
        data: nil, width: size, height: size, bitsPerComponent: 8, bytesPerRow: 0,
        space: space, bitmapInfo: CGImageAlphaInfo.noneSkipLast.rawValue)
else {
    FileHandle.standardError.write(Data("Could not create a drawing context\n".utf8))
    exit(1)
}

func rgb(_ hex: UInt32) -> CGColor {
    CGColor(
        srgbRed: CGFloat((hex >> 16) & 0xFF) / 255,
        green: CGFloat((hex >> 8) & 0xFF) / 255,
        blue: CGFloat(hex & 0xFF) / 255,
        alpha: 1)
}

// Background: indigo with a gentle top-to-bottom deepening.
if let gradient = CGGradient(
    colorsSpace: space, colors: [rgb(0x5B53EC), rgb(0x4338CA)] as CFArray, locations: [0, 1]) {
    ctx.drawLinearGradient(
        gradient, start: CGPoint(x: 0, y: CGFloat(size)), end: .zero, options: [])
} else {
    ctx.setFillColor(rgb(0x4F46E5))
    ctx.fill(CGRect(x: 0, y: 0, width: size, height: size))
}

// The mark's visible bbox is x5–27, y5–28.5 on the 32 grid. Scale it so the
// bars span ~56% of the icon width and centre the bbox.
let unit = CGFloat(size) * 0.56 / 22
let bboxWidth = 22 * unit
let bboxHeight = 23.5 * unit
let originX = (CGFloat(size) - bboxWidth) / 2
let topY = (CGFloat(size) - bboxHeight) / 2

ctx.setFillColor(rgb(0xFFFFFF))
let bars: [(y: CGFloat, length: CGFloat)] = [(0, 22), (6.5, 22), (13, 15), (19.5, 8)]
for bar in bars {
    // CoreGraphics' origin is bottom-left; convert from top-down grid units.
    let y = CGFloat(size) - (topY + bar.y * unit) - 4 * unit
    ctx.fill(CGRect(x: originX, y: y, width: bar.length * unit, height: 4 * unit))
}

guard let image = ctx.makeImage(),
      let dest = CGImageDestinationCreateWithURL(
        URL(fileURLWithPath: output) as CFURL, UTType.png.identifier as CFString, 1, nil)
else {
    FileHandle.standardError.write(Data("Could not create \(output)\n".utf8))
    exit(1)
}
CGImageDestinationAddImage(dest, image, nil)
if !CGImageDestinationFinalize(dest) {
    FileHandle.standardError.write(Data("Could not write \(output)\n".utf8))
    exit(1)
}
print("Wrote \(output)")
