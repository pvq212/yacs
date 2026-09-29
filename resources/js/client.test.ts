import { describe, it, expect } from "vitest";
import { compareSequence, sendOnEnter } from "./client";
describe("訊息序號與中文輸入", () => {
    it("大於 Number 安全範圍的序號仍能精確排序", () => {
        expect(compareSequence("9007199254740993", "9007199254740992")).toBe(1);
        expect(compareSequence("10", "9")).toBe(1);
        expect(compareSequence("0", "0")).toBe(0);
    });
    it("中文組字與 Shift Enter 不觸發送出", () => {
        expect(
            sendOnEnter({
                key: "Enter",
                shiftKey: false,
                isComposing: true,
                keyCode: 229,
            } as KeyboardEvent),
        ).toBe(false);
        expect(
            sendOnEnter({
                key: "Enter",
                shiftKey: true,
                isComposing: false,
                keyCode: 13,
            } as KeyboardEvent),
        ).toBe(false);
        expect(
            sendOnEnter({
                key: "Enter",
                shiftKey: false,
                isComposing: false,
                keyCode: 13,
            } as KeyboardEvent),
        ).toBe(true);
    });
});
