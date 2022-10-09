<?php

function urlExists($url) {
    $handle = curl_init($url);
    curl_setopt($handle,  CURLOPT_RETURNTRANSFER, TRUE);

    $response = curl_exec($handle);
    $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);

    if($httpCode >= 200 && $httpCode <= 400) {
        return true;
    } else {
        return true;
    }

    curl_close($handle);
}

function __chapterGenerator($data) {
    $result = array();
    $novel_title = $data->novel->name;

    if ( substr($data->url, 0, 4) == "http" ) {
        $novel_url = $data->url;
    } else {
        $novel_url = $data->novel->group->url . $data->url;
    }

    if ( $data->novel->group->id == 6 ) {
        if ( $data->novel->alternative_url != "" ) {
            $c = floor($data->chapter);

            $novel_url = $data->novel->alternative_url . $c;
        }
    } else if ( $data->novel->group->id == 1 ) {
        if ( $data->novel->alternative_url != "" ) {
            $c = floor($data->chapter);

            $novel_url = $data->novel->alternative_url . $c;
        }
    } else if ( $data->novel->group->id == 3 ) {
        if ( $data->novel->alternative_url != "" ) {
            if ( $data->novel->id == 72 ) {
                $c = str_replace(".", "-", $data->chapter);
            } else {
                $c = floor($data->chapter);
            }

            $novel_url = $data->novel->alternative_url . $c;
        }
    }

    if ( urlExists($novel_url) == true ) {
        $crawler = Goutte::request('GET', $novel_url);

        switch ($data->novel->group->id) {
            case 1: // WuxiaWorld
                $crawler->filter('.fr-view > p')->each(function ($node) use (&$result, $novel_title) {
                    if ( $node->text() != $novel_title ) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    }
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 9 ) {
                    $crawler->filter('.fr-view > div > p')->each(function ($node) use (&$result, $novel_title) {
                        if ( $node->text() != $novel_title ) {
                            array_push($result, '<p>' . $node->text() . '</p>');
                        }
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $crawler->filter('.fr-view > div')->each(function ($node) use (&$result, $novel_title) {
                        if ( $node->text() != $novel_title ) {
                            array_push($result, '<p>' . $node->text() . '</p>');
                        }
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $crawler->filter('.fr-view > div > div > p')->each(function ($node) use (&$result, $novel_title) {
                        if ( $node->text() != $novel_title ) {
                            array_push($result, '<p>' . $node->text() . '</p>');
                        }
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $crawler->filter('.fr-view > div > div > div > p')->each(function ($node) use (&$result, $novel_title) {
                        if ( $node->text() != $novel_title ) {
                            array_push($result, '<p>' . $node->text() . '</p>');
                        }
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $crawler->filter('.fr-view > div > div > div > div > p')->each(function ($node) use (&$result, $novel_title) {
                        if ( $node->text() != $novel_title ) {
                            array_push($result, '<p>' . $node->text() . '</p>');
                        }
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $crawler->filter('.fr-view')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $crawler->filter('.text-left > p')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->html() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $crawler->filter('.text-left > div > p')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->html() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $crawler->filter('.text-left > div > div > p')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->html() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $result = __splitLargeString($result);
                }
                break;
            case 2: // Gravity Tales
                $crawler->filter('.fr-view > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 5 ) {
                    $crawler->filter('.Paragraph')->each(function ($node) use (&$result) {
//                        if ( $node->parents()->filter('.fr-view')->attr('id') != "chapterNotes" ) {
                        array_push($result, '<p>' . $node->text() . '</p>');
//                        }
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.fr-view > div > div > p')->each(function ($node) use (&$result) {
                        if ( $node->parents()->filter('.fr-view')->attr('id') != "chapterNotes" ) {
                            array_push($result, '<p>' . $node->text() . '</p>');
                        }
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.fr-view > header > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.fr-view')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);

                    $result = __splitLargeString($result);
                }
                break;
            case 3: // Volare
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 5 ) {
                    $crawler->filter('.entry-content > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.entry-content > p > span')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.cha-words > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $crawler->filter('.text-left > p')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->html() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $crawler->filter('.chapter-content3 > div > p > strong')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->html() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $crawler->filter('.jfontsize_content > p')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->html() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }
                break;
            case 4: // KobatoChan
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                if ( count($result) < 9 ) {
                    $crawler->filter('.entry-content > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });
                }
                break;
            case 5: // LiberSpark
                $crawler->filter('.reader-content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });
                break;
            case 6: // Qidian
                $crawler->filter('.cha-words > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->html() . "<p>");
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 9 ) {
                    $result = __splitLargeString($result);
                }

                $result = __cleanseChapterArray($result);

                if ( count($result) < 3 ) {
                    $crawler->filter('.fr-view > p')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->html() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);

                    if ( count($result) < 9 ) {
                        $crawler->filter('.cha-content > .cha-words > .cha-words > p')->each(function ($node) use (&$result) {
                            array_push($result, "<p>" . $node->html() . "<p>");
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $crawler->filter('.fr-view > div > div > p')->each(function ($node) use (&$result) {
                            array_push($result, "<p>" . $node->html() . "<p>");
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $crawler->filter('.fr-view')->each(function ($node) use (&$result) {
                            array_push($result, "<p>" . $node->html() . "<p>");
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                            array_push($result, "<p>" . $node->html() . "<p>");
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $crawler->filter('#chapter-content > p')->each(function ($node) use (&$result) {
                            array_push($result, "<p>" . $node->text() . "<p>");
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $crawler->filter('#vung_doc > p')->each(function ($node) use (&$result) {
                            array_push($result, "<p>" . $node->html() . "<p>");
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $crawler->filter('.chapter-content3 > div > p')->each(function ($node) use (&$result) {
                            array_push($result, "<p>" . $node->html() . "<p>");
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $crawler->filter('.chapter-content3 > div')->each(function ($node) use (&$result) {
                            if ( strlen($node->html()) > 1000 ) {
                                $paragraphs = preg_split('/<br><br>+/', $node->html());

                                foreach ( $paragraphs as $p ) {
                                    if ( strlen($p) > 0 ) {
                                        array_push($result, "<p>" . $p. "<p>");
                                    }
                                }
                            }
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $crawler->filter('#list_chapter > div')->each(function ($node) use (&$result) {
                            if ( strlen($node->html()) > 1000 ) {
                                $paragraphs = preg_split('/<br><br>+/', $node->html());

                                foreach ( $paragraphs as $p ) {
                                    if ( strlen($p) > 0 ) {
                                        array_push($result, "<p>" . $p. "<p>");
                                    }
                                }
                            }
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $crawler->filter('.text-left > p')->each(function ($node) use (&$result) {
                            array_push($result, "<p>" . $node->html() . "<p>");
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $crawler->filter('.text-left > div > p')->each(function ($node) use (&$result) {
                            array_push($result, "<p>" . $node->html() . "<p>");
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $crawler->filter('.text-left > div > div > p')->each(function ($node) use (&$result) {
                            array_push($result, "<p>" . $node->html() . "<p>");
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $crawler->filter('.cha-words > div')->each(function ($node) use (&$result) {
                            $node->filter('div > p')->each(function($n) use (&$result) {
                                array_push($result, "<p>" . $n->html() . "<p>");
                            });
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $result = __splitLargeString($result);
                    }
                }
                break;
            case 7: // Creative Novels
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                if ( count($result) < 9 ) {
                    $result = __splitLargeString($result);
                }
                break;
            case 8: // Dreams of JiangHu
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });
                break;
            case 9: // FictionPress
                $result = array();
                $dom = new DOMDocument();
                @$dom->loadHTML(file_get_contents($novel_url));

                if ( isset($dom->getElementById('storytext')->childNodes) ) {
                    foreach ( $dom->getElementById('storytext')->childNodes as $item ) {
                        array_push($result, "<p>" . $item->textContent . "</p>");
                    }
                }

                $result = __cleanseChapterArray($result);
                break;
            case 10: // Radiant Translation
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) == 0 ) {
                    $crawler->filter('article > p')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->text() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) == 0 ) {
                    $crawler->filter('.fr-view')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->text() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) == 0 ) {
                    $crawler->filter('.chapter-body > p')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->text() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }
                break;
            case 11: // Royal Road
                $crawler->filter('.chapter-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) == 0 ) {
                    $crawler->filter('.chapter-content > div > div')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->text() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) == 0 ) {
                    $result = $crawler->filter('.chapter-content')->text();

                    $result = __cleanseChapterArray($result);
                }

                if ( is_array($result) ) {
                    if ( count($result) < 6 ) {
                        $result = __splitLargeString($result);
                    }
                } else {
                    $result = __splitLargeString($result);
                }
                break;
            case 12: // Halosty's Tales
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);
                break;
            case 13: // Jiggly Puff's Diary
                $crawler->filter('.entry-content > div > span > span > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) == 0 ) {
                    $crawler->filter('.entry-content > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) == 0 ) {
                    $crawler->filter('.entry-content > div > span')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) == 0 ) {
                    $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.entry-content > div > span > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.entry-content > div > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }
                break;
            case 14: // Blue Silver Translations
                $iframe = $crawler->filter('.entry-content > iframe')->each(function ($node) {
                    return $node->attr('src');
                });

                if ( !isset($iframe[0]) ) {
                    $iframe = $crawler->filter('.entry-content > p > iframe')->each(function ($node) {
                        return $node->attr('src');
                    });
                }

                $crawler = Goutte::request('GET', $iframe[0]);
                $crawler->filter('p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);

//                $crawler->filter('.book-intro-para > p')->each(function ($node) use (&$result) {
//                    array_push($result, '<p>' . $node->text() . '</p>');
//                });
//
//                $result = __cleanseChapterArray($result);
                break;
            case 15: // Translation Nation
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) == 0 ) {
                    $crawler->filter('.entry-content > div > p > span')->each(function ($node) {
                        foreach ($node as $item) {
                            $item->parentNode->removeChild($item);
                        }
                    });

                    $crawler->filter('.entry-content > div > p')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->text() . "<p>");
                    });

                    $result = __splitLargeString($result);
                }
                break;
            case 16: // DM Translations
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 9 ) {
                    $crawler->filter('.entry-content > div > div')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->text() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $crawler->filter('.entry-content > div > p')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->text() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $result = __splitLargeString($result);
                }
                break;
            case 17: // Wolfie Honyaku
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);
                break;
            case 18: // Divine Dao Library
                $crawler->filter('.post-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) == 0 ) {
                    $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->text() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }
                break;
            case 19: // WuxiaNation
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);
                break;
            case 20: // Myoniyoni Translations
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);
                break;
            case 22: // Humanity, Fuck Yeah
                $crawler->filter('.field-item > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);
                break;
            case 23: // A Practical Guide to Evil
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 9 ) {
                    $result = __splitLargeString($result);
                }
                break;
            case 24: // Snowy Publications
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 9 ) {
                    $result = __splitLargeString($result);
                }
                break;
            case 25: // The Iron Teeth Serial
                $crawler->filter('.post_content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) == 0 ) {
                    $crawler->filter('.post_content > div > div > p')->each(function ($node) {
                        array_push($result, "<p>" . $node->text() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $result = __splitLargeString($result);
                }
                break;
            case 26: // Parahumans
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);
                break;
            case 27: // Oppa Translations
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);
                break;
            case 28: // Dark Blood Age
                $crawler->filter('.entry-content > div')->each(function ($node) use (&$result) {
                    array_push($result, $node->html());
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 5 ) {
                    $result = array();
                    $crawler->filter('.entry-content')->each(function ($node) use (&$result) {
                        array_push($result, $node->html());
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $result = __splitLargeString($result);
                }

                if ( count($result) < 10 ) {
                    $result = array();
                    $crawler->filter('.entry-content > span')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }
                break;
            case 29: // Elysiel
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 9 ) {
                    $crawler->filter('.entry-content > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });
                }

                $result = __cleanseChapterArray($result);
                break;
            case 30: // Kokuma Translations
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 5 ) {
                    $crawler->filter('.public-DraftStyleDefault-block > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }
                break;
            case 31: // The Wandering Inn
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);
                break;
            case 32: // Light Novels Translations
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);
                break;
            case 33: // Totally Insane Translations
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 5 ) {
                    $crawler->filter('.entry-content > div > div > div > div > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.the-content > p > span')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.the-content > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.the-content > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.post-content > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.post-content > p > span')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }
                break;
            case 34: // Light Novels Bastion
                $crawler->filter('._1V5-components-Post--postContentWrapper > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);

                $crawler->filter('.container > .row > div > section > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                if ( count($result) < 9 ) {
                    $result = __splitLargeString($result);
                }

                $result = __cleanseChapterArray($result);

                if ( count($result) < 5 ) {
                    $crawler->filter('.container > .row > div > section > div')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }
                break;
            case 35: // Novel Full
                $crawler->filter('#chapter-content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 5 ) {
                    $crawler->filter('#chapter-content > blockquote > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('article > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('#chapter-content > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('#chapter-content > div > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('#chapter-content > div > div > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }      

                if ( count($result) < 5 ) {
                    $crawler->filter('#chapter-content > div > div > div > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('#chapter-content > div > div > div > div > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('#chapter-content > div > div > div > div > div > div > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('#chapter-content > div > div > div > div > div > div > div > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('#chapter-content > div > div > div > div > div > div > div > div > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('#chapter-content > div > div')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }      

                if ( count($result) < 5 ) {
                    $crawler->filter('#chapter-content > div')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('#chapter-content > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.reading-content > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.reading-content > .text-left')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.reading-content > div > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.reading-content > div > div')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }
                break;
            case 36: // Korea Novels
                $crawler->filter('#content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);
                break;
            case 38: // Box Novel
                $crawler->filter('.reading-content > .text-left > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 5 ) {
                    $crawler->filter('.reading-content > .text-left > .entry-header > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.reading-content > .text-left > .entry-content > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.reading-content > .text-left > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.cha-words > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.cha-content > .cha-words > .cha-words > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.cha-words > .cha-words > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.cha-paragraph')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.well > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }
                break;
            case 39: // Wuxia League
                $crawler->filter('#mw-content-text > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);
                break;
            case 40: // Read Light novels
                $crawler->filter('.chapter-content3 > .desc')->each(function ($node) use (&$result) {
                    $text = str_replace("<br>", "=|br|=", $node->html());
                    $text = preg_replace('/<[^>]*>/', '', $text);
                    $text = "<p>" . str_replace("=|br|==|br|=", "</p><p>", $text);
                    $text = str_replace("=|br|=", "", $text);

                    array_push($result, $text);
                });

                $crawler->filter('.chapter-content3 > .desc > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);
                break;
            case 41: // Box Novel Org
                $crawler->filter('#chr-content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 5 ) {
                    $crawler->filter('#chapter-content > blockquote > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('#chapter-content > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.reading-content > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.reading-content > div > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }
                break;
        }

        if ( is_array($result) ) {
            $result = __cleanseChapterArray($result);
        }
    } else {
        $result = array();
    }

    return $result;
}

function __tableOfContentGenerator($data) {
    if ( urlExists($data->translator_url) == true ) {
        $result = array();
        $crawler = Goutte::request('GET', $data->translator_url);

        switch ($data->group_id) {
            case 1: // WuxiaWorld
                $crawler->filter('.chapter-item > a')->each(function ($node, $key) use (&$result) {
                    $label = trim($node->text());
                    $url = trim($node->attr('href'));

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;

            case 2: // Gravity Tales
                $crawler = Goutte::request('GET', $data->translator_url . "/chapters");

                $crawler->filter('.table > tbody > tr > td > a')->each(function ($node, $key) use (&$result) {
                    $label = trim($node->text());
                    $url = trim($node->attr('href'));

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 3: // Volare
                $crawler->filter('.chapter-item > a')->each(function ($node) use (&$result) {
                    $label = trim($node->text());
                    $url = trim($node->attr('href'));

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 4: // KobatoChan
                $crawler->filter('.entry-content > p > a')->each(function ($node, $key) use (&$result) {
                    if ( stripos(strtolower($node->text()), "chapter") === false ) {
                        $label = trim("Chapter " . $key . " - " . $node->text());
                    } else {
                        $label = $node->text();
                    }

                    $url = trim($node->attr('href'));

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 5: // LiberSpark
                $crawler->filter('.chapter-link-container > td > a')->each(function ($node, $key) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 6: // Qidian
                $csrf = "1DAntuKsCJgB6YoT8mC9nBy0S6CA6SmVtBwVDZNG";
                $qidian_weird_token = "1555242888527";

                $chapter_list_array = file_get_contents("https://www.webnovel.com/apiajax/chapter/GetChapterList?_csrfToken=" . $csrf . "&bookId=" . $data->unique_id . "&_=" . $qidian_weird_token);

                if ( is_array(json_decode($chapter_list_array)) || is_object(json_decode($chapter_list_array)) ) {
                    foreach (json_decode($chapter_list_array) as $key => $item) {
                        if ($key == "data") {
                            foreach ($item as $k => $i) {
                                if ($k == "bookInfo") {
                                    $book_id = $i->bookId;
                                    $book_name = str_replace(" ", "-", $i->bookName);
                                    $book_name = str_replace(":", "%3A", $book_name);
                                    $book_name = str_replace(",", "%2C", $book_name);
                                }

                                if ($k == "volumeItems") {
                                    foreach ($i as $k_v => $v) {
                                        foreach ($v->chapterItems as $c) {
                                            $chapter_name = str_replace(" ", "-", $c->name);

                                            $label = "Book " . $v->index . " Chapter " . $c->index . " - " . $c->name;
                                            $url = "https://www.webnovel.com/book/" . $book_id . "/" . $c->id . "/" . $book_name . "/" . $chapter_name;

                                            array_push($result, __tocChapterLabelGenerator($label, $url));
                                        }
                                    }
                                } else {
                                    if ($k == "chapterItems") {
                                        foreach ($i as $c) {
                                            $chapter_name = str_replace(" ", "-", $c->chapterName);

                                            $label = "Chapter " . $c->chapterIndex . " - " . $c->chapterName;
                                            $url = "https://www.webnovel.com/book/" . $book_id . "/" . $c->chapterId . "/" . $book_name . "/" . $chapter_name;

//                                            echo var_dump($label);

                                            array_push($result, __tocChapterLabelGenerator($label, $url));
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                break;
            case 7: // Creative Novels
                $crawler->filter('.post_box > a')->each(function ($node, $key) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 8: // Dreams of Jianghu
                $crawler->filter('.entry-content > p > a')->each(function ($node, $key) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    $labelArr = explode('“', $label);

                    if ( count($labelArr) > 1 ) {
                        $chapterNumber = __convertWordToNumber(strtolower(trim(str_replace("Chapter ", "", $labelArr[0]))));

                        $label = "Chapter " . $chapterNumber . " - " . str_replace("”", "", $labelArr[1]);

                        array_push($result, __tocChapterLabelGenerator($label, $url));
                    }
                });
                break;
            case 9: // Fiction Press
                $dom = new DOMDocument();
                @$dom->loadHTML(file_get_contents($data->translator_url));

                if ( isset($dom->getElementById('chap_select')->childNodes) ) {
                    foreach ( $dom->getElementById('chap_select')->childNodes as $key => $item ) {
                        $label = "Chapter " . str_replace(".", " - ", $item->textContent);
                        $url = $data->chapter_url . ($key + 1) . "/" . str_replace(" ", "-", $data->name);

                        array_push($result, __tocChapterLabelGenerator($label, $url));
                    }
                }
                break;
            case 10:
                $crawler->filter('table > tbody > tr > td > a')->each(function ($node, $key) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });

                if ( count($result) == 0 ) {
                    $crawler->filter('.entry-content > p > a')->each(function ($node, $key) use (&$result) {
                        $label = trim($node->text());
                        $url = $node->attr('href');

                        array_push($result, __tocChapterLabelGenerator($label, $url));
                    });
                }

                if ( count($result) == 0 ) {
                    $crawler->filter('.entry-content > div > p > a')->each(function ($node, $key) use (&$result) {
                        $label = trim($node->text());
                        $url = $node->attr('href');

                        array_push($result, __tocChapterLabelGenerator($label, $url));
                    });
                }
                break;
            case 11: // Royal Road
                $crawler->filter('table > tbody > tr > td > a')->each(function ($node, $key) use (&$result) {
                    $url = $node->attr('href');

                    if ( $url !== NULL ) {
                        $label = trim($node->text());
                        array_push($result, __tocChapterLabelGenerator($label, $url));
                    }
                });
                break;
            case 12: // Halosty's Tales
                $crawler->filter('.entry-content > p > a')->each(function ($node, $key) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 13: // Jiggly Puff's Diary
                $crawler->filter('.w4pl-inner > ul > li > a')->each(function ($node, $key) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 14: // Blue Silver Translations
                $crawler->filter('.entry-content > ul > li > a')->each(function ($node, $key) use (&$result) {
                    $label = "Chapter " . trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 15: // Translation Nations
                $crawler->filter('.entry-content > table > tbody > tr > td > a')->each(function ($node, $key) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });

                if ( count($result) == 0 ) {
                    $crawler->filter('.panel-body > table > tbody > tr > td > a')->each(function ($node, $key) use (&$result) {
                        $label = trim($node->text());
                        $url = $node->attr('href');

                        array_push($result, __tocChapterLabelGenerator($label, $url));
                    });
                }
                break;
            case 16: // DM Translations
                $crawler->filter('.entry-content > p > a')->each(function ($node) use (&$result) {
                    if ( stripos(strtolower($node->text()), "chapter") === false ) {
                        $label = "Chapter " . trim($node->text());
                    } else {
                        $label = trim($node->text());
                    }
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 17: // Wolfie Honyaku
                $crawler->filter('.text-6404-0-0-4 > div > p > a')->each(function ($node) use (&$result) {
                    if ( stripos(strtolower($node->text()), "chapter") === false ) {
                        $label = "Chapter " . trim($node->text());
                    } else {
                        $label = trim($node->text());
                    }
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 18: // Divine Dao Library
                $crawler->filter('.post-content > div > ul > li > span > a')->each(function ($node) use (&$result) {
                    if ( stripos(strtolower($node->text()), "chapter") === false ) {
                        $label = "Chapter " . trim($node->text());
                    } else {
                        $label = trim($node->text());
                    }
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });

                $crawler->filter('.post-content > div > ul > li > a')->each(function ($node) use (&$result) {
                    if ( stripos(strtolower($node->text()), "chapter") === false ) {
                        $label = "Chapter " . trim($node->text());
                    } else {
                        $label = trim($node->text());
                    }
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 20: // Myoniyoni Translations
                $crawler->filter('.x-accordion > .x-accordion-group > .x-accordion-body > .x-accordion-inner > a')->each(function ($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });

                if ( count($result) == 0 ) {
                    $crawler->filter('.x-accordion > .x-accordion-group > .x-accordion-body > .x-accordion-inner > p > a')->each(function ($node) use (&$result) {
                        $label = trim($node->text());
                        $url = $node->attr('href');

                        array_push($result, __tocChapterLabelGenerator($label, $url));
                    });
                }

                $crawler->filter('.x-accordion > .x-accordion-group > .x-accordion-body > .x-accordion-inner > p')->each(function ($node) use (&$result) {
                    $node->filter('a')->each(function($item) use (&$result) {
                        $label = trim($item->text());
                        $url = $item->attr('href');

                        array_push($result, __tocChapterLabelGenerator($label, $url));
                    });
                });
                break;
            case 22: // Humanity, Fuck Yeah
                $crawler->filter('.menu > li > a')->each(function ($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 23: // A Practical Guide to Evil
                for ( $i = 0; $i < count($crawler->filter('.entry-content > ul')); $i++ ) {
                    $book = $crawler->filter('.entry-content > ul')->eq($i);

                    $book->filter('li > a')->each(function($node) use ($i, &$result) {
                        $label = "Book " . ($i + 1) . " - " . trim($node->text());
                        $url = $node->attr('href');

                        array_push($result, __tocChapterLabelGenerator($label, $url));
                    });
                }
                break;
            case 24: // Snowy Publications
                $crawler->filter('.page-item-6 > ul > .page_item_has_children > ul > li > a')->each(function($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 25: // The Iron Teeth Serial
                for ( $i = 0; $i < count($crawler->filter('.page > .su-accordion')); $i++ ) {
                    $book = $crawler->filter('.page > .su-accordion')->eq($i);

                    for ( $j = 0; $j < count($book->filter('.su-spoiler-content')); $j++ ) {
                        $arc = $book->filter('.su-spoiler-content')->eq($j);

                        $arc->filter('ul > li > a')->each(function($node, $a) use ($i, $j, &$result) {
                            $label = "Book " . ($i + 1) . " - Chapter " . ($j + 1) . "." . $a . " - " . trim($node->text());
                            $url = $node->attr('href');

                            if ( stripos(strtolower($url), "patreon") === false ) {
                                array_push($result, __tocChapterLabelGenerator($label, $url));
                            }
                        });
                    }
                }
                break;
            case 26: // Parahumans
                $crawler->filter('.entry-content > p > strong > a')->each(function($node) use (&$result) {
                    $label = "Chapter " . trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });

                $crawler->filter('.entry-content > div > strong > a')->each(function($node) use (&$result) {
                    $label = "Chapter " . trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 27: // Oppa Translations
                $crawler->filter('.entry-content > p > a')->each(function($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 28: // Dark Blood Age
                $crawler->filter('.entry-content > div > table > tbody > tr > td > a')->each(function($node) use (&$result) {
                    $label = filter_var($node->text(), FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_HIGH);
                    $label = str_replace("Chapter", "Chapter ", $label);
                    $label = str_replace("  ", " ", $label);
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 29: // Elysiel
                $crawler->filter('.entry-content > p > a')->each(function ($node, $key) use (&$result) {
                    if ( stripos(strtolower($node->text()), "chapter") === false ) {
                        $label = trim("Chapter " . $key . " - " . $node->text());
                    } else {
                        $label = $node->text();
                    }
                    $url = trim($node->attr('href'));

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 30: // Kokuma Translations
                $crawler->filter('.entry-content > p > strong > span > a')->each(function($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });

                $crawler->filter('.entry-content > p > span > strong > a')->each(function($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 31: // The Wandering Inn
                $crawler->filter('.entry-content > p > a')->each(function($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 32: // Light Novels Translations
                $crawler->filter('.su-spoiler-content > p > a')->each(function($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 33: // Totally Insane Translation
                $crawler->filter('.tab-pane > p > em > u > a')->each(function($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });

                $crawler->filter('.tab-pane > p > a')->each(function($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });

                $crawler->filter('.tab-pane > p > em > span > a')->each(function($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });

                $crawler->filter('.tab-pane > p > span > em > a')->each(function($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });

                $crawler->filter('.chapters-acc > li > a')->each(function($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 34: // Light Novels Bastion
                $crawler->filter('.container > .row > div > section > ul')->each(function($node) use (&$result) {
                    foreach ( $node->filter("li > a > span")  as $item ) {
                        $item->parentNode->removeChild($item);
                    }
                });

                $crawler->filter('.container > .row > div > section > ul')->each(function($node) use (&$result) {
                    $label = str_replace("The Tutorial Is Too Hard ", "Chapter ", trim($node->filter('li > a')->text()));
                    $label = str_replace("The Tutorial is too Hard ", "Chapter ", $label);
                    $label = str_replace("Max Level Newbie ", "Chapter ", $label);
                    $url = $node->filter('li > a')->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 35: // Novel Full
                $total = 0;

                $crawler->filter('.last > a')->each(function($node) use (&$total) {
                    $a = explode("&", $node->attr('href'));
                    $b = explode("=", $a[0]);

                    $total = $b[1];
                });

                for ( $i = 0; $i <= 100; $i++ ) {
                    $crawler = Goutte::request('GET', $data->translator_url . "?page=" . $i . "&per_page=50");
                    $crawler->filter('.list-chapter > li > a')->each(function($node) use (&$result, &$total) {
                        $label = trim($node->attr('title'));
                        $label = str_replace("Dragoon ", "Chapter ", $label);
                        $url = $node->attr('href');

                        array_push($result, __tocChapterLabelGenerator($label, "http://novelfull.com" . $url));
                    });
                }
                break;
            case 36: // Korea Novels
                $crawler->filter('ul > li > a')->each(function($node) use (&$result) {
                    $label = $node->attr('title');
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 38: // Box Novel
                $crawler->filter('.wp-manga-chapter > a')->each(function ($node, $key) use (&$result) {
                    $label = $node->text();
                    $url = trim($node->attr('href'));

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 40: // Read Light Novels
                $crawler->filter('.chapter-chs > li > a')->each(function ($node, $key) use (&$result) {
                    $label = $node->text();
                    $url = trim($node->attr('href'));

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 41: // Box Novel Org
                for ( $i = 1; $i <= 50; $i++ ) {
                    $crawler = Goutte::request('GET', $data->translator_url . "?page=" . $i);
                    $crawler->filter('.list-chapter > li > a')->each(function($node) use (&$result) {
                        $label = trim($node->attr('title'));
                        $label = str_replace("Dragoon ", "Chapter ", $label);
                        $url = $node->attr('href');

                        array_push($result, __tocChapterLabelGenerator($label, $url));
                    });
                }
                break;
        }

        // remove nulls
        $result_data = array();
        $generateChapter = true;
        foreach ( $result as $item ) {
            if ( $item !== NULL ) {
                array_push($result_data, $item);

                if ( $item["chapter"] > 0 ) {
                    $generateChapter = false;
                }
            }
        }

        if ( $generateChapter == true ) {
            $result_chapters_data = $result_data;
            $result_data = array();
            foreach ( $result_chapters_data as $key => $item ) {
                array_push($result_data, array(
                    "label" => $item["label"],
                    "book" => $item["book"],
                    "url" => $item["url"],
                    "chapter" => ($key + 1)
                ));
            }
        }

        return $result_data;
    } else {
        return array();
    }
}

function __getMetadata($data) {
    $metadata = array();

    $name = str_replace("!", "", str_replace("?", "", str_replace("'", "", str_replace(" ", "-", strtolower($data->name)))));
    $name = str_replace(",", "", $name);
    $name = str_replace("'", "", $name);

    $crawler = Goutte::request('GET', 'https://www.novelupdates.com/series/' . $name);
    $crawler->filter('#editdescription')->each(function ($node, $key) use (&$metadata) {
        $metadata["description"] = $node->html();
    });

    $crawler->filter('#authtag')->each(function ($node, $key) use (&$metadata) {
        if ( $key == 0 ) {
            $metadata["author"] = $node->text();
        }
    });

    $crawler->filter('#editstatus')->each(function ($node, $key) use (&$metadata) {
        $array = explode(" ", str_replace("Chapter ", "Chapters ", $node->text()));
        $key = array_search("Chapters", $array);

        $metadata["no_of_chapters"] = $key == 0 ? 0 : intval(trim($array[$key - 1]));
    });

    $crawler->filter(".seriesimg > img")->each(function($node, $key) use (&$metadata) {
        $url = trim($node->attr('src'));
        $metadata["image"] = $url;
    });

    return $metadata;
}

function __convertWordToNumber($object) {
    // Replace all number words with an equivalent numeric value
    $data = strtr(
        $object,
        array(
            'zero'      => '0',
            'a'         => '1',
            'one'       => '1',
            'two'       => '2',
            'three'     => '3',
            'four'      => '4',
            'five'      => '5',
            'six'       => '6',
            'seven'     => '7',
            'eight'     => '8',
            'nine'      => '9',
            'ten'       => '10',
            'eleven'    => '11',
            'twelve'    => '12',
            'thirteen'  => '13',
            'fourteen'  => '14',
            'fifteen'   => '15',
            'sixteen'   => '16',
            'seventeen' => '17',
            'eighteen'  => '18',
            'nineteen'  => '19',
            'twenty'    => '20',
            'thirty'    => '30',
            'forty'     => '40',
            'fourty'    => '40', // common misspelling
            'fifty'     => '50',
            'sixty'     => '60',
            'seventy'   => '70',
            'eighty'    => '80',
            'ninety'    => '90',
            'hundred'   => '100',
            'thousand'  => '1000',
            'million'   => '1000000',
            'billion'   => '1000000000',
            'and'       => '',
        )
    );

// Coerce all tokens to numbers
    $parts = array_map(
        function ($val) {
            return floatval($val);
        },
        preg_split('/[\s-]+/', $data)
    );

    $stack = new SplStack; // Current work stack
    $sum   = 0; // Running total
    $last  = null;

    foreach ($parts as $part) {
        if (!$stack->isEmpty()) {
            // We're part way through a phrase
            if ($stack->top() > $part) {
                // Decreasing step, e.g. from hundreds to ones
                if ($last >= 1000) {
                    // If we drop from more than 1000 then we've finished the phrase
                    $sum += $stack->pop();
                    // This is the first element of a new phrase
                    $stack->push($part);
                } else {
                    // Drop down from less than 1000, just addition
                    // e.g. "seventy one" -> "70 1" -> "70 + 1"
                    $stack->push($stack->pop() + $part);
                }
            } else {
                // Increasing step, e.g ones to hundreds
                $stack->push($stack->pop() * $part);
            }
        } else {
            // This is the first element of a new phrase
            $stack->push($part);
        }

        // Store the last processed part
        $last = $part;
    }

    return $sum + $stack->pop();
}

function __generateHTMLContent($object) {
    $content = '<?xml version="1.0" encoding="utf-8"?>';
    $content .= '<package version="3.0" unique-identifier="BookId" xmlns="http://www.idpf.org/2007/opf">';
    $content .= '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">';
    $content .= '<dc:language>en</dc:language>';
    $content .= '<dc:title>' . $object->name . '</dc:title>';
    $content .= '<dc:creator id="cre">' . $object->author . '</dc:creator>';
    $content .= '<meta refines="#cre" scheme="marc:relators" property="role">aut</meta>';
    $content .= '<dc:identifier id="BookId">urn:uuid:de5b9c8f-ce5a-4b5c-b418-e50dba48e2b9</dc:identifier>';
    $content .= '<meta content="0.9.9" name="Sigil version" />';
    $content .= '<meta property="dcterms:modified">2018-03-21T09:42:16Z</meta>';
    $content .= '</metadata>';
    $content .= '<manifest>';
    $content .= '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>';
    foreach ( $object->chapters as $item ) {
        $content .= '<item id="' . str_replace("/Novel/" . $item->novel_id . "/Text/", "", $item->html_file) . '" href="Text/' . str_replace("/Novel/" . $item->novel_id . "/Text/", "", $item->html_file) . '" media-type="application/xhtml+xml"/>';
    }
    $content .= '<item id="nav.xhtml" href="Text/nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>';
    $content .= '</manifest>';
    $content .= '<spine toc="ncx">';
    foreach ( $object->chapters as $item ) {
        $content .= '<itemref idref="' . str_replace("/Novel/" . $item->novel_id . "/Text/", "", $item->html_file) . '"/>';
    }
    $content .= '<itemref idref="nav.xhtml" linear="no"/>';
    $content .= '</spine>';
    $content .= '</package>';

    return $content;
}

function __generateHTMLToc($object) {
    $content = '<?xml version="1.0" encoding="utf-8" ?>';
    $content .= '<ncx version="2005-1" xmlns="http://www.daisy.org/z3986/2005/ncx/">';
    $content .= '<head>';
    $content .= '<meta content="urn:uuid:de5b9c8f-ce5a-4b5c-b418-e50dba48e2b9" name="dtb:uid"/>';
    $content .= '<meta content="0" name="dtb:depth"/>';
    $content .= '<meta content="0" name="dtb:totalPageCount"/>';
    $content .= '<meta content="0" name="dtb:maxPageNumber"/>';
    $content .= '</head>';
    $content .= '<docTitle>';
    $content .= '<text>Unknown</text>';
    $content .= '</docTitle>';
    $content .= '<navMap>';
    $content .= '<navPoint id="navPoint-1" playOrder="1">';
    $content .= '<navLabel>';
    $content .= '<text>Start</text>';
    $content .= '</navLabel>';
    $content .= '<content src="Text/' . str_replace("/Novel/" . $object->chapters[0]->novel_id . "/Text/", "", $object->chapters[0]->html_file) . '"/>';
    $content .= '</navPoint>';
    $content .= '</navMap>';
    $content .= '</ncx>';

    return $content;
}

function __generateHTMLNav($string) {    
    $content = '<?xml version="1.0" encoding="utf-8"?>';
    $content .= '<!DOCTYPE html>';
    $content .= '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" lang="en" xml:lang="en">';
    $content .= '<head>';
    $content .= '<meta charset="utf-8"/>';
    $content .= '<title></title>';
    $content .= '</head>';
    $content .= '<body epub:type="frontmatter">';
    $content .= '<nav epub:type="toc" id="toc">';
    $content .= '<h1>Table of Contents</h1>';
    $content .= '<ol>';

    foreach ( $string as $s ) {
        $content .= '<li><a href="../Text/' . str_replace("/Novel/" . $s->novel_id . "/Text/", "", $s->html_file) . '">' . $s->label . '</a></li>';
    }

    $content .= '</ol>';
    $content .= '</nav>';
    $content .= '<nav epub:type="landmarks" id="landmarks" hidden="">';
    $content .= '<h2>Landmarks</h2>';
    $content .= '<ol>';
    $content .= '<li>';
    $content .= '<a epub:type="toc" href="#toc">Table of Contents</a>';
    $content .= '</li>';
    $content .= '</ol>';
    $content .= '</nav>';
    $content .= '</body>';
    $content .= '</html>';

    return $content;
}

function __generateHTMLChapter($string) {
    $content = '<?xml version="1.0" encoding="utf-8"?>';
    $content .= '<!DOCTYPE html>';
    $content .= '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">';
    $content .= '<head>';
    $content .= '<title></title>';
    $content .= '</head>';
    $content .= '<body>';
    $content .= $string;
    $content .= '</body>';
    $content .= '</html>';

    return $content;
}

function __splitLargeString($string) {
    $result = array();

    foreach ( $string as $c ) {
        $string = preg_split('/(?<=[.?!])\s+(?=[a-z])/i', $c);

        foreach ( $string as $s ) {
            array_push($result, "<p>" . $s . "</p>");
        }
    }

    return __cleanseChapterArray($result);
}

function __cleanseChapterArray($string) {
    $cleanseArray = array();

    foreach ( $string as $i ) {
        if ( $i != "<p></p>" ) {
            if ( $i != "<p>&nbsp;</p>" ) {
                if ( $i != "<p>&nbsp; &nbsp;</p>" ) {
                    if ( $i != "<p><p>" ) {
                        if ( $i != "<p>  </p>" ) {
                            if ( $i != "<p>   </p>" ) {
                                if ( $i != "<p> </p>" ) {
                                    array_push($cleanseArray, __cleanseChapterContent($i));
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    return $cleanseArray;
}

function __cleanseChapterContent($string) {
    $string = str_replace("<Mystic Moon>", "Mystic Moon", $string);
    $string = preg_replace(array('/\s{2,}/', '/[\t\n]/'), ' ', $string);
    $string = preg_replace('/[\t\n\r\0\x0B]/', '', $string);
    $string = preg_replace('/([\s])\1+/', ' ', $string);
    $string = preg_replace("/[[:blank:]]+/"," ", $string);
    $string = preg_replace('/\s+/', ' ', $string);

    if ( stripos(strtolower($string), "table of contents") === false ) {
        if ( stripos(strtolower($string), "previous chapter") === false ) {
            if (stripos(strtolower($string), "translated by") === false) {
                if (stripos(strtolower($string), "edited by") === false) {
                    if (stripos(strtolower($string), "translator") === false) {
                        if (stripos(strtolower($string), "A+
						A-") === false) {
                            if (stripos(strtolower($string), "edited:") === false) {
                                if (stripos(strtolower($string), "tl: ") === false) {
                                    if (stripos(strtolower($string), "next chapter") === false) {
                                        if (stripos(strtolower($string), "previous chapter") === false) {
                                            if (stripos(strtolower($string), "proofreader:") === false) {
                                                if (stripos(strtolower($string), "(tl by ") === false) {
                                                    return trim($string);
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

function __tocChapterLabelGenerator($label, $url) {
    if (stripos($label, "teaser") === false) {
        $endloop = 0;
        $counter_chapter = 0;
        $chapter = 0;
        $alphabets = ['a', 'b', 'c', 'd', 'e', 'f'];

        $counter_book = 0;
        $book = 0;
        $bookCheck = 0;

        $label = str_replace("(", " (", $label);
        $label = str_replace("  ", " ", $label);

//            echo $label . "---";
        $label_arr = explode(" ", $label);

        foreach ($label_arr as $item) {
            $new_item = strtolower($item);

            if (
                is_numeric(substr($new_item, 0, 1)) &&
                substr($new_item, 1, 1) == "-" &&
                is_numeric(substr($new_item, 2, 1))
            ) {
                $new_item = str_replace("-", ".", $new_item);
            }

            if (
                is_numeric(substr($new_item, 0, 2)) &&
                substr($new_item, 2, 1) == "-" &&
                is_numeric(substr($new_item, 3, 1))
            ) {
                $new_item = str_replace("-", ".", $new_item);
            }

            if (
                is_numeric(substr($new_item, 0, 3)) &&
                substr($new_item, 3, 1) == "-" &&
                is_numeric(substr($new_item, 4, 1))
            ) {
                $new_item = str_replace("-", ".", $new_item);
            }

            $new_item = str_replace(":", "", $new_item);
            $new_item = str_replace(";", "", $new_item);
//                $new_item = str_replace("-", "", $new_item);
//                $new_item = str_replace("–", "", $new_item);
            $new_item = str_replace("!", "", $new_item);
            $new_item = str_replace(",", "", $new_item);
            $new_item = str_replace("!", "", $new_item);
            $new_item = preg_replace('/[^A-Za-z0-9 _\.\-\+\&]/', '', $new_item);

//                    echo $item . "---";
//                    echo bin2hex($new_item)  . "---";

            if ($counter_chapter == 1 && $endloop == 0) {
//                    echo " e --" . $new_item . "-- ";
                if ($new_item != "chapter") {
//                        echo " f";
                    if (is_numeric($new_item) || is_numeric(floatval($new_item))) {
                        $chapter = $new_item;

                        if ($label_arr[count($label_arr) - 1]) {
                            if (substr($label_arr[count($label_arr) - 1], 0, 1) == "(" && substr($label_arr[count($label_arr) - 1], -1, 1) == ")") {
                                $subchapter = substr($label_arr[count($label_arr) - 1], 1, 1);

                                if (!is_numeric($subchapter)) {
                                    $subchapter = array_search($subchapter, $alphabets) + 1;
                                }

                                $chapter = $chapter . "." . $subchapter;
                            }
                        }

                        $endloop = 1;

//                            echo " a" . $chapter;

                        $counter_chapter = 0;
                    } else {
                        $arr = preg_split('/(?<=[0-9])(?=[a-z]+)/i', $new_item);

                        if (count($arr) > 1) {
                            $chapter = $arr[0] . '.' . (array_search($arr[1], $alphabets) + 1);

                            $endloop = 1;

//                                echo " b" . $chapter;
                        } else {
                            /** check for Alphabets inside parentheses */
                            $check_alphabets_parantheses = explode("(", $new_item);

                            if (count($check_alphabets_parantheses) > 1) {
                                $subchapter = str_replace(")", "", $check_alphabets_parantheses[1]);

                                $chapter = $check_alphabets_parantheses[0] . "." . (array_search(strtolower($subchapter), $alphabets) + 1);

                                $endloop = 1;
                            }

//                                echo " c" . $chapter;
                        }

//                            echo " d";

                        $counter_chapter = 0;
                    }
                }
            }

            if ($counter_book == 1) {
                $book = intval($new_item);

                $counter_book = 0;
            }

            if ($new_item == "chapter" || $new_item == "ast" || $new_item == "episode") {
                if ($counter_chapter == 0) {
                    $counter_chapter++;
                }
            }

            if ($new_item == "book" || $new_item == "volume" || $new_item == "vol") {
                if ($bookCheck == 0) {
                    $counter_book++;
                    $bookCheck++;
                }
            }
        }

        $chapter = str_replace("..", ".", $chapter);
        $chapter = str_replace("(???)", "", $chapter);

        if (substr($chapter, -1) == ".") {
            $chapter = substr($chapter, 0, -1);
        }

//            echo $chapter . " -- ";

        $check_characters = preg_split('/(?<=[0-9])(?=[a-z]+)/i', $chapter);

        if (count($check_characters) > 1) {
            $chapter = $check_characters[0];

            if (strlen($check_characters[1]) == 1) {
                if (in_array(strtolower($check_characters[1]), $alphabets)) {
                    $chapter = $chapter . "." . (array_search(strtolower($check_characters[1]), $alphabets) + 1);
                }
            }
        }

        /** check for Alphabets inside parentheses */
        $check_alphabets_parantheses = explode("(", $chapter);

        if (count($check_alphabets_parantheses) > 1) {
            $subchapter = str_replace(")", "", $check_alphabets_parantheses[1]);

            $chapter = $check_alphabets_parantheses[0] . "." . (array_search(strtolower($subchapter), $alphabets) + 1);
        }

        if ($book == 0) { // check for book number in url
            $check_books_url = explode("-", $url);

            foreach ($check_books_url as $key => $item) {
                if ($item == "book" || $item == "volume") {
                    if (isset($check_books_url[$key + 1])) {
                        $book = $check_books_url[$key + 1];
                    }
                }
            }
        }

//            echo $chapter . "---";

        if (!is_float($chapter)) {
            $check_for_decimal = explode(".", $chapter);

            if (count($check_for_decimal) == 1) {
                if (stripos(strtolower($label), "[part 1]") == true) {
//                        echo "m1";
                    $chapter = $chapter . ".1";
                } else if (stripos(strtolower($label), "[part 2]") == true) {
//                        echo "m2";
                    $chapter = $chapter . ".2";
                } else if (stripos(strtolower($label), "(part 1)") == true) {
//                        echo "m3";
                    $chapter = $chapter . ".1";
                } else if (stripos(strtolower($label), "(part 2)") == true) {
//                        echo "m4";
                    $chapter = $chapter . ".2";
                } else if (stripos(strtolower($label), "part 1:") == true) {
//                        echo "m5";
                    $chapter = $chapter . ".1";
                } else if (stripos(strtolower($label), "part 2:") == true) {
//                        echo "m6";
                    $chapter = $chapter . ".2";
                } else if (stripos(strtolower($label), " part 1 ") == true) {
//                        echo "m7";
                    $chapter = $chapter . ".1";
                } else if (stripos(strtolower($label), " part 2 ") == true) {
//                        echo "m8";
                    $chapter = $chapter . ".2";
                } else if (stripos(strtolower($label), " - 1 - ") == true) {
//                        echo "m9";
                    $chapter = $chapter . ".1";
                } else if (stripos(strtolower($label), " - 2 - ") == true) {
//                        echo "m10";
                    $chapter = $chapter . ".2";
                } else if (stripos(strtolower($label), " - 1: ") == true) {
//                        echo "m11";
                    $chapter = $chapter . ".1";
                } else if (stripos(strtolower($label), " - 2: ") == true) {
//                        echo "m12";
                    $chapter = $chapter . ".2";
                } else if (stripos(strtolower($label), "(part 1/2)") == true) {
//                        echo "m13";
                    $chapter = $chapter . ".1";
                } else if (stripos(strtolower($label), "(part 2/2)") == true) {
//                        echo "m14";
                    $chapter = $chapter . ".2";
                } else if (stripos(strtolower($label), "(Part ½)") == true) {
//                        echo "m15";
                    $chapter = $chapter . ".1";
                } else if (stripos(strtolower($label), " (1/1)") == true) {
//                        echo "m16";
                    $chapter = $chapter . ".1";
                } else if (stripos(strtolower($label), " (2/2") == true) {
//                        echo "m17";
                    $chapter = $chapter . ".2";
                } else if (stripos(strtolower($label), " - 1>") == true) {
                    $chapter = $chapter . ".1";
                } else if (stripos(strtolower($label), " - 2>") == true) {
                    $chapter = $chapter . ".2";
                } else if (stripos(strtolower($label), " - 3>") == true) {
                    $chapter = $chapter . ".3";
                } else if (stripos(strtolower($label), " - 4>") == true) {
                    $chapter = $chapter . ".4";
                } else if (stripos(strtolower($label), " - 5>") == true) {
                    $chapter = $chapter . ".5";
                } else if (stripos(strtolower($label), " - 6>") == true) {
                    $chapter = $chapter . ".6";
                }
            }

        }

//        echo var_dump($label);

        $check_letters_length = preg_replace('/[^a-zA-Z]/', '', $chapter);

        if (strlen($check_letters_length) == 1) {
//                echo "m13";

            $chapter = preg_replace('/[^0-9]/', '', $chapter) . "." . (array_search(strtolower($check_letters_length), $alphabets) + 1);
        } else if (strlen($check_letters_length) > 1) {
//                echo "m14";
            $chapter = preg_replace('/[^0-9]/', '', $chapter);
        }

        if (substr($chapter, -1, 1) == "-") {
            $chapter = substr($chapter, 0, -1);
        }

        if (substr($chapter, -1, 1) == ".") {
            $chapter = substr($chapter, 0, -1);
        }

        if (substr($chapter, -2, 1) == "-") {
            $chapter = str_replace("-", ".", $chapter);
        }

        $chapter = str_replace("-.", ".", $chapter);

        if ( count(explode(".", $chapter)) > 1 ) {
            $tempChapter = explode(".", $chapter);

            $chapter = $tempChapter[0] . "." . $tempChapter[1];
        }

        if ( $chapter == 0 ) {
//            echo "a";
            if ( strpos($url, "https://lightnovelstranslations.com/road-to-kingdom/chapter-") !== false ) {
//                echo "b";
                $chapter_temp_string = str_replace("https://lightnovelstranslations.com/road-to-kingdom/chapter-", "", $url);
//                echo $chapter_temp_string;
                $chapter_temp_array = explode("-", $chapter_temp_string);

                $chapter = $chapter_temp_array[0];

//                echo var_dump($chapter_temp_array);
            }
        }

//            echo $chapter;

//            echo "<br>";


        if ( strpos($url, 'dtpb-chapter-1') == true ) {
            $book = 1;
        }

        if ( strpos($url, 'dtpb-chapter-2') == true ) {
            $book = 2;
        }

        if ( strpos($url, 'dtpb-chapter-3') == true ) {
            $book = 3;
        }

        if ( strpos($url, 'dtpb-chapter-4') == true ) {
            $book = 4;
        }

        if ( strpos($url, 'dtpb-chapter-5') == true ) {
            $book = 5;
        }

        if ( strpos($url, 'dtpb-chapter-6') == true ) {
            $book = 6;
        }

        if ( strpos($url, 'dtpb-chapter-7') == true ) {
            $book = 7;
        }

        if ( strpos($url, 'www.readlightnovel.org') == true ) {
            $tmpUrl = str_replace("https://www.readlightnovel.org/banished-to-another-world/chapter-", "", $url);

            $chapter = intval($tmpUrl);
        }

        return array(
            "label" => substr($label, 0, 250),
            "book" => $book,
            "url" => $url,
            "chapter" => $chapter
        );
    }
}
